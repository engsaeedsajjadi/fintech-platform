<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\StateMachines;

use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Exceptions\InvalidTradeTransitionException;

/**
 * Pure state machine for the SafeDeal trade lifecycle.
 *
 * Every transition is an explicit (from, action) -> to edge. Anything not
 * listed is rejected with InvalidTradeTransitionException — there is no
 * silent fallback and no way to jump states (e.g. DRAFT -> SETTLED).
 */
final class SafeDealStateMachine
{
    /**
     * @var array<string, array<string, TradeStatus>>
     */
    private const TRANSITIONS = [
        'draft' => [
            'request_verification' => TradeStatus::PENDING_VERIFICATION,
            'cancel'               => TradeStatus::CANCELLED,
        ],
        'pending_verification' => [
            'mark_verified'       => TradeStatus::VERIFIED,
            'reject_verification' => TradeStatus::DRAFT,
            'cancel'              => TradeStatus::CANCELLED,
        ],
        'verified' => [
            'submit_agreement' => TradeStatus::AGREEMENT_PENDING,
            'cancel'           => TradeStatus::CANCELLED,
        ],
        'agreement_pending' => [
            'accept_agreement' => TradeStatus::AGREED,
            'reject_agreement' => TradeStatus::VERIFIED,
            'cancel'           => TradeStatus::CANCELLED,
        ],
        'agreed' => [
            'submit_documents' => TradeStatus::DOCUMENTS_PENDING,
            'update_terms'     => TradeStatus::AGREEMENT_PENDING,
            'cancel'           => TradeStatus::CANCELLED,
        ],
        'documents_pending' => [
            'review_documents' => TradeStatus::DOCUMENTS_REVIEWED,
            'cancel'           => TradeStatus::CANCELLED,
        ],
        'documents_reviewed' => [
            'assess_risk'       => TradeStatus::RISK_ASSESSED,
            'request_documents' => TradeStatus::AGREED,
            'cancel'            => TradeStatus::CANCELLED,
        ],
        'risk_assessed' => [
            'authorize_funding' => TradeStatus::FUNDING_PENDING,
            'reject_risk'       => TradeStatus::CANCELLED,
            'cancel'            => TradeStatus::CANCELLED,
        ],
        'funding_pending' => [
            'confirm_payment' => TradeStatus::FUNDED,
            'cancel'          => TradeStatus::CANCELLED,
        ],
        'funded' => [
            'start_milestones' => TradeStatus::IN_PROGRESS,
            'open_dispute'     => TradeStatus::DISPUTED,
            'cancel'           => TradeStatus::REFUNDED,
        ],
        'in_progress' => [
            'all_milestones_done' => TradeStatus::DELIVERY_PENDING,
            'open_dispute'        => TradeStatus::DISPUTED,
            'cancel'              => TradeStatus::REFUNDED,
        ],
        'delivery_pending' => [
            'confirm_delivery' => TradeStatus::DELIVERED,
            'open_dispute'     => TradeStatus::DISPUTED,
            'cancel'           => TradeStatus::REFUNDED,
        ],
        'delivered' => [
            'record_approval' => TradeStatus::APPROVALS_PENDING,
            'open_dispute'    => TradeStatus::DISPUTED,
            'cancel'          => TradeStatus::REFUNDED,
        ],
        'approvals_pending' => [
            'all_approved' => TradeStatus::SETTLEMENT_ELIGIBLE,
            'open_dispute' => TradeStatus::DISPUTED,
            'cancel'       => TradeStatus::REFUNDED,
        ],
        'settlement_eligible' => [
            'settle'       => TradeStatus::SETTLED,
            'open_dispute' => TradeStatus::DISPUTED,
            'cancel'       => TradeStatus::REFUNDED,
        ],
        'disputed' => [
            'resolve_release' => TradeStatus::SETTLEMENT_ELIGIBLE,
            'resolve_return'  => TradeStatus::REFUNDED,
            'resolve_split'   => TradeStatus::SETTLEMENT_ELIGIBLE,
        ],
        'settled'   => [],
        'cancelled' => [],
        'refunded'  => [],
    ];

    public static function can(TradeStatus $from, string $action): bool
    {
        return isset(self::TRANSITIONS[$from->value][$action]);
    }

    public static function apply(TradeStatus $from, string $action): TradeStatus
    {
        $to = self::TRANSITIONS[$from->value][$action] ?? null;

        if ($to === null) {
            throw new InvalidTradeTransitionException(
                "Invalid SafeDeal transition: action '{$action}' is not allowed from status '{$from->value}'."
            );
        }

        return $to;
    }

    /**
     * @return list<string>
     */
    public static function allowedActions(TradeStatus $from): array
    {
        return array_keys(self::TRANSITIONS[$from->value] ?? []);
    }
}
