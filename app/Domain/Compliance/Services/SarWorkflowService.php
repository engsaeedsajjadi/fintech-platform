<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\SuspiciousActivityReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Suspicious Activity Report workflow: create → review → escalate → file.
 *
 * States (existing model statuses, unchanged): draft → pending_review →
 * submitted → closed. Escalation raises priority to critical and returns
 * the SAR to pending_review; it never files anything.
 *
 * Filing is deliberately manual: there is NO live regulator filing
 * integration in-repo, so fileExternally() only records a filing when a
 * human supplies the real reference obtained from the regulator's own
 * filing portal, after a `file_sar` decision and QA approval. It never
 * invents a reference (the legacy simulated BSA reference is disabled —
 * see SuspiciousActivityReportService::submitToRegulator()).
 */
final class SarWorkflowService
{
    /** Create a SAR in draft. */
    public function create(array $data, ?User $subject = null): SuspiciousActivityReport
    {
        $description = trim((string) ($data['activity_description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('SAR activity_description is required.');
        }

        return DB::transaction(function () use ($data, $subject, $description) {
            $sar = SuspiciousActivityReport::create([
                'status'               => SuspiciousActivityReport::STATUS_DRAFT,
                'priority'             => $data['priority'] ?? SuspiciousActivityReport::PRIORITY_MEDIUM,
                'subject_user_id'      => $subject?->id ?? ($data['subject_user_id'] ?? null),
                'subject_type'         => $data['subject_type'] ?? SuspiciousActivityReport::SUBJECT_TYPE_CUSTOMER,
                'subject_details'      => $data['subject_details'] ?? ['name' => $subject?->name ?? 'Unknown'],
                'activity_start_date'  => $data['activity_start_date'] ?? now(),
                'activity_end_date'    => $data['activity_end_date'] ?? now(),
                'total_amount'         => $data['total_amount'] ?? 0,
                'primary_currency'     => $data['primary_currency'] ?? 'USD',
                'transaction_count'    => $data['transaction_count'] ?? 0,
                'involved_accounts'    => $data['involved_accounts'] ?? [],
                'involved_parties'     => $data['involved_parties'] ?? [],
                'activity_types'       => $data['activity_types'] ?? ['other'],
                'activity_description' => $description,
                'red_flags'            => $data['red_flags'] ?? [],
                'triggering_rules'     => $data['triggering_rules'] ?? [],
                'related_transactions' => $data['related_transactions'] ?? [],
                'is_confidential'      => true,
            ]);

            AuditLog::log(
                'sar.created',
                $subject,
                null,
                ['sar_number' => $sar->sar_number, 'status' => $sar->status],
                ['sar_id'     => $sar->getKey(), 'priority' => $sar->priority],
                'sar,compliance,aml'
            );

            return $sar;
        });
    }

    /** Move a draft SAR to pending review and assign an investigator. */
    public function submitForReview(SuspiciousActivityReport $sar, User $investigator): SuspiciousActivityReport
    {
        if (! $sar->isDraft()) {
            throw new LogicException('Only a draft SAR can be submitted for review.');
        }

        return DB::transaction(function () use ($sar, $investigator) {
            $sar->startInvestigation($investigator);

            AuditLog::log(
                'sar.submitted_for_review',
                $sar->subjectUser,
                ['status'     => SuspiciousActivityReport::STATUS_DRAFT],
                ['status'     => $sar->status, 'investigator_id' => $investigator->id],
                ['sar_number' => $sar->sar_number],
                'sar,compliance,aml'
            );

            return $sar->refresh();
        });
    }

    /**
     * Escalate: priority → critical, back to pending review with a reason.
     * A closed or already-submitted SAR cannot be escalated.
     */
    public function escalate(SuspiciousActivityReport $sar, User $by, string $reason): SuspiciousActivityReport
    {
        if ($sar->isClosed() || $sar->isSubmitted()) {
            throw new LogicException('A closed or submitted SAR cannot be escalated.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('An escalation reason is required.');
        }

        return DB::transaction(function () use ($sar, $by, $reason) {
            $oldPriority = $sar->priority;

            $sar->update([
                'priority'        => SuspiciousActivityReport::PRIORITY_CRITICAL,
                'status'          => SuspiciousActivityReport::STATUS_PENDING_REVIEW,
                'review_comments' => $reason,
                'reviewed_by'     => $by->id,
                'reviewed_at'     => now(),
            ]);

            AuditLog::log(
                'sar.escalated',
                $sar->subjectUser,
                ['priority'   => $oldPriority],
                ['priority'   => SuspiciousActivityReport::PRIORITY_CRITICAL, 'status' => $sar->status],
                ['sar_number' => $sar->sar_number, 'reason' => $reason, 'escalated_by' => $by->id],
                'sar,compliance,aml'
            );

            return $sar->refresh();
        });
    }

    /** Record the investigation decision (file_sar / no_action / continue_monitoring). */
    public function recordDecision(
        SuspiciousActivityReport $sar,
        string $decision,
        string $rationale,
        User $decisionMaker
    ): SuspiciousActivityReport {
        if (! in_array($decision, [
            SuspiciousActivityReport::DECISION_FILE_SAR,
            SuspiciousActivityReport::DECISION_NO_ACTION,
            SuspiciousActivityReport::DECISION_CONTINUE_MONITORING,
        ], true)) {
            throw new InvalidArgumentException("Unknown SAR decision: {$decision}");
        }

        return DB::transaction(function () use ($sar, $decision, $rationale, $decisionMaker) {
            $sar->makeDecision($decision, $rationale, $decisionMaker);

            AuditLog::log(
                'sar.decision',
                $sar->subjectUser,
                null,
                ['decision'   => $decision],
                ['sar_number' => $sar->sar_number, 'rationale' => $rationale, 'decision_maker_id' => $decisionMaker->id],
                'sar,compliance,aml'
            );

            return $sar->refresh();
        });
    }

    /** QA review of a SAR (approve the filing package or send it back). */
    public function recordQaReview(SuspiciousActivityReport $sar, User $reviewer, string $comments, bool $approved): SuspiciousActivityReport
    {
        return DB::transaction(function () use ($sar, $reviewer, $comments, $approved) {
            $sar->addReview($comments, $approved, $reviewer);

            AuditLog::log(
                'sar.qa_review',
                $sar->subjectUser,
                null,
                ['qa_approved' => $approved],
                ['sar_number'  => $sar->sar_number, 'comments' => $comments, 'reviewer_id' => $reviewer->id],
                'sar,compliance,aml'
            );

            return $sar->refresh();
        });
    }

    /**
     * Record a real external filing. The $reference must be the reference
     * the regulator actually issued (from its filing portal) — it is never
     * generated here. Requires a `file_sar` decision and QA approval first.
     */
    public function fileExternally(
        SuspiciousActivityReport $sar,
        string $reference,
        string $jurisdiction,
        User $filedBy
    ): SuspiciousActivityReport {
        if (trim($reference) === '' || trim($jurisdiction) === '') {
            throw new InvalidArgumentException('A real regulator filing reference and jurisdiction are required to record a filing.');
        }

        if ($sar->decision !== SuspiciousActivityReport::DECISION_FILE_SAR) {
            throw new LogicException('SAR cannot be filed: no file_sar decision has been recorded.');
        }

        if (! $sar->qa_approved) {
            throw new LogicException('SAR cannot be filed: QA approval is required first.');
        }

        return DB::transaction(function () use ($sar, $reference, $jurisdiction, $filedBy) {
            $sar->fileWithRegulator($reference, $jurisdiction);

            AuditLog::log(
                'sar.filed',
                $sar->subjectUser,
                ['status'     => SuspiciousActivityReport::STATUS_PENDING_REVIEW],
                ['status'     => SuspiciousActivityReport::STATUS_SUBMITTED, 'filing_reference' => $reference],
                ['sar_number' => $sar->sar_number, 'jurisdiction' => $jurisdiction, 'filed_by' => $filedBy->id],
                'sar,compliance,aml'
            );

            return $sar->refresh();
        });
    }
}
