<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Enums;

/**
 * Lifecycle states of a SafeDeal trade.
 *
 * Terminal states: SETTLED, CANCELLED, REFUNDED.
 */
enum TradeStatus: string
{
    case DRAFT = 'draft';
    case PENDING_VERIFICATION = 'pending_verification';
    case VERIFIED = 'verified';
    case AGREEMENT_PENDING = 'agreement_pending';
    case AGREED = 'agreed';
    case DOCUMENTS_PENDING = 'documents_pending';
    case DOCUMENTS_REVIEWED = 'documents_reviewed';
    case RISK_ASSESSED = 'risk_assessed';
    case FUNDING_PENDING = 'funding_pending';
    case FUNDED = 'funded';
    case IN_PROGRESS = 'in_progress';
    case DELIVERY_PENDING = 'delivery_pending';
    case DELIVERED = 'delivered';
    case APPROVALS_PENDING = 'approvals_pending';
    case SETTLEMENT_ELIGIBLE = 'settlement_eligible';
    case DISPUTED = 'disputed';
    case SETTLED = 'settled';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SETTLED, self::CANCELLED, self::REFUNDED], true);
    }

    public function isFundedStage(): bool
    {
        return in_array($this, [
            self::FUNDED,
            self::IN_PROGRESS,
            self::DELIVERY_PENDING,
            self::DELIVERED,
            self::APPROVALS_PENDING,
            self::SETTLEMENT_ELIGIBLE,
            self::DISPUTED,
        ], true);
    }
}
