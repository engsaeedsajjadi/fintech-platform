<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycManualReview;
use App\Domain\Compliance\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Manual admin review queue for KYC verifications.
 *
 * This is the ONLY path by which a sandbox / simulated verification result
 * can become an approval: a human reviewer inspects the evidence and
 * decides. The service never auto-approves, and approval/rejection are
 * recorded twice — once as an append-only KycManualReview row, once as an
 * AuditLog entry — so the decision history is immutable and attributable.
 */
final class KycManualReviewService
{
    public function __construct(
        private readonly KycService $kycService,
    ) {
    }

    /** Queue a verification for manual review. Idempotent for an already-queued verification. */
    public function submitForReview(KycVerification $verification, string $reason, ?User $submittedBy = null): KycManualReview
    {
        return DB::transaction(function () use ($verification, $reason, $submittedBy) {
            $existing = KycManualReview::where('kyc_verification_id', $verification->getKey())
                ->where('decision', KycManualReview::DECISION_PENDING)
                ->latest()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $verification->update(['status' => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW]);

            $review = KycManualReview::create([
                'kyc_verification_id' => $verification->getKey(),
                'user_id'             => $verification->user_id,
                'reviewer_id'         => $submittedBy?->id,
                'decision'            => KycManualReview::DECISION_PENDING,
                'reason'              => $reason,
            ]);

            AuditLog::log(
                'kyc.manual_review.requested',
                $verification->user,
                ['kyc_status'      => $verification->user->kyc_status],
                ['kyc_status'      => 'requires_manual_review'],
                ['verification_id' => $verification->getKey(), 'reason' => $reason],
                'kyc,compliance,manual-review'
            );

            return $review;
        });
    }

    /** Human approval. Sets the verification completed and the user KYC level. */
    public function approve(KycVerification $verification, User $reviewer, ?string $notes = null, array $options = []): KycManualReview
    {
        return DB::transaction(function () use ($verification, $reviewer, $notes, $options) {
            $this->assertReviewable($verification);

            $level = $options['level'] ?? $this->levelForVerification($verification);

            $review = KycManualReview::create([
                'kyc_verification_id' => $verification->getKey(),
                'user_id'             => $verification->user_id,
                'reviewer_id'         => $reviewer->id,
                'decision'            => KycManualReview::DECISION_APPROVED,
                'reason'              => $options['reason'] ?? 'Manual review approved',
                'notes'               => $notes,
                'reviewed_at'         => now(),
            ]);

            $verification->update([
                'status'       => KycVerification::STATUS_COMPLETED,
                'completed_at' => now(),
                'reviewed_by'  => $reviewer->id,
                'reviewed_at'  => now(),
                'review_notes' => $notes,
            ]);

            $this->kycService->verifyKyc($verification->user, (string) $reviewer->id, array_merge(
                ['level' => $level],
                $options
            ));

            AuditLog::log(
                'kyc.manual_review.approved',
                $verification->user,
                ['kyc_status'      => 'requires_manual_review'],
                ['kyc_status'      => 'approved', 'kyc_level' => $level],
                ['verification_id' => $verification->getKey(), 'reviewer_id' => $reviewer->id, 'notes' => $notes],
                'kyc,compliance,manual-review'
            );

            return $review;
        });
    }

    /** Human rejection. The user must resubmit; nothing is auto-approved. */
    public function reject(KycVerification $verification, User $reviewer, string $reason, ?string $notes = null): KycManualReview
    {
        return DB::transaction(function () use ($verification, $reviewer, $reason, $notes) {
            $this->assertReviewable($verification);

            $review = KycManualReview::create([
                'kyc_verification_id' => $verification->getKey(),
                'user_id'             => $verification->user_id,
                'reviewer_id'         => $reviewer->id,
                'decision'            => KycManualReview::DECISION_REJECTED,
                'reason'              => $reason,
                'notes'               => $notes,
                'reviewed_at'         => now(),
            ]);

            $verification->update([
                'status'         => KycVerification::STATUS_FAILED,
                'failure_reason' => $reason,
                'completed_at'   => now(),
                'reviewed_by'    => $reviewer->id,
                'reviewed_at'    => now(),
                'review_notes'   => $notes,
            ]);

            $this->kycService->rejectKyc($verification->user, (string) $reviewer->id, $reason);

            AuditLog::log(
                'kyc.manual_review.rejected',
                $verification->user,
                ['kyc_status'      => 'requires_manual_review'],
                ['kyc_status'      => 'rejected'],
                ['verification_id' => $verification->getKey(), 'reviewer_id' => $reviewer->id, 'reason' => $reason],
                'kyc,compliance,manual-review'
            );

            return $review;
        });
    }

    /** The pending manual-review queue (oldest first). */
    public function queue(): \Illuminate\Database\Eloquent\Collection
    {
        return KycManualReview::where('decision', KycManualReview::DECISION_PENDING)
            ->with(['verification', 'user'])
            ->oldest()
            ->get();
    }

    /** Full decision history for one user, newest first (immutable rows). */
    public function historyForUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return KycManualReview::where('user_id', $user->id)
            ->with(['verification', 'reviewer'])
            ->latest()
            ->get();
    }

    /** @throws LogicException */
    private function assertReviewable(KycVerification $verification): void
    {
        if (in_array($verification->status, [KycVerification::STATUS_COMPLETED, KycVerification::STATUS_EXPIRED], true)) {
            throw new LogicException(sprintf(
                'KYC verification %s is already %s and cannot be reviewed again.',
                $verification->getKey(),
                $verification->status,
            ));
        }
    }

    private function levelForVerification(KycVerification $verification): string
    {
        return match ($verification->type) {
            KycVerification::TYPE_ENHANCED_DUE_DILIGENCE                => 'full',
            KycVerification::TYPE_ADDRESS, KycVerification::TYPE_INCOME => 'enhanced',
            default                                                     => 'basic',
        };
    }
}
