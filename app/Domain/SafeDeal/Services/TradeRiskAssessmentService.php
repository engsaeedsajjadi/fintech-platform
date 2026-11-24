<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Services;

use App\Domain\SafeDeal\Enums\RiskLevel;

/**
 * Deterministic, rule-based trade risk assessment.
 *
 * No ML, no external scoring provider: every factor is a named rule with
 * a fixed weight, so a score is reproducible from the trade alone.
 * Score >= 70 rejects the trade; 40–69 flags manual review (trade may
 * proceed but is marked); < 40 is low risk.
 */
class TradeRiskAssessmentService
{
    public const REJECT_AT = 70;

    public const REVIEW_AT = 40;

    /**
     * @param array<string, mixed> $context
     * @return array{score: int, level: RiskLevel, factors: list<string>, requires_review: bool, rejected: bool}
     */
    public function assess(array $context): array
    {
        $score = 0;
        $factors = [];

        $amount = (string) ($context['amount'] ?? '0');

        if (bccomp($amount, '50000.0000', 4) >= 0) {
            $score += 30;
            $factors[] = 'amount>=50000';
        } elseif (bccomp($amount, '10000.0000', 4) >= 0) {
            $score += 15;
            $factors[] = 'amount>=10000';
        }

        if (
            ($context['buyer_level'] ?? null) !== CounterpartyVerificationService::ENHANCED
            || ($context['seller_level'] ?? null) !== CounterpartyVerificationService::ENHANCED
        ) {
            $score += 20;
            $factors[] = 'party-without-enhanced-verification';
        }

        $documentsApproved = (int) ($context['documents_approved'] ?? 0);
        $documentsRequired = (int) ($context['documents_required'] ?? 0);

        if ($documentsRequired > 0 && $documentsApproved < $documentsRequired) {
            $score += 25;
            $factors[] = 'incomplete-documents';
        }

        if (empty($context['has_delivery_deadline'])) {
            $score += 10;
            $factors[] = 'no-delivery-deadline';
        }

        if ((int) ($context['milestone_count'] ?? 0) === 0) {
            $score += 10;
            $factors[] = 'no-milestones';
        }

        if (! empty($context['prior_disputes'])) {
            $score += 15;
            $factors[] = 'prior-disputes';
        }

        $score = min(100, $score);

        $level = match (true) {
            $score >= self::REJECT_AT => RiskLevel::REJECTED,
            $score >= self::REVIEW_AT => RiskLevel::MEDIUM,
            default                   => RiskLevel::LOW,
        };

        return [
            'score'           => $score,
            'level'           => $level,
            'factors'         => $factors,
            'requires_review' => $score >= self::REVIEW_AT && $score < self::REJECT_AT,
            'rejected'        => $score >= self::REJECT_AT,
        ];
    }
}
