<?php

declare(strict_types=1);

namespace Tests\Domain\SafeDeal;

use App\Domain\SafeDeal\Enums\RiskLevel;
use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Exceptions\InvalidTradeTransitionException;
use App\Domain\SafeDeal\Services\TradeRiskAssessmentService;
use App\Domain\SafeDeal\StateMachines\SafeDealStateMachine;
use Tests\TestCase;

class SafeDealStateMachineTest extends TestCase
{
    public function test_happy_path_transitions(): void
    {
        $status = TradeStatus::DRAFT;
        $steps = [
            'request_verification' => TradeStatus::PENDING_VERIFICATION,
            'mark_verified'        => TradeStatus::VERIFIED,
            'submit_agreement'     => TradeStatus::AGREEMENT_PENDING,
            'accept_agreement'     => TradeStatus::AGREED,
            'submit_documents'     => TradeStatus::DOCUMENTS_PENDING,
            'review_documents'     => TradeStatus::DOCUMENTS_REVIEWED,
            'assess_risk'          => TradeStatus::RISK_ASSESSED,
            'authorize_funding'    => TradeStatus::FUNDING_PENDING,
            'confirm_payment'      => TradeStatus::FUNDED,
            'start_milestones'     => TradeStatus::IN_PROGRESS,
            'all_milestones_done'  => TradeStatus::DELIVERY_PENDING,
            'confirm_delivery'     => TradeStatus::DELIVERED,
            'record_approval'      => TradeStatus::APPROVALS_PENDING,
            'all_approved'         => TradeStatus::SETTLEMENT_ELIGIBLE,
            'settle'               => TradeStatus::SETTLED,
        ];

        foreach ($steps as $action => $expected) {
            $status = SafeDealStateMachine::apply($status, $action);
            $this->assertSame($expected, $status);
        }

        $this->assertTrue(TradeStatus::SETTLED->isTerminal());
    }

    public function test_invalid_transitions_throw(): void
    {
        foreach ([
            [TradeStatus::DRAFT, 'settle'],
            [TradeStatus::DRAFT, 'confirm_payment'],
            [TradeStatus::FUNDED, 'assess_risk'],
            [TradeStatus::SETTLED, 'cancel'],
            [TradeStatus::CANCELLED, 'settle'],
            [TradeStatus::REFUNDED, 'open_dispute'],
            [TradeStatus::DELIVERED, 'confirm_payment'],
        ] as [$from, $action]) {
            try {
                SafeDealStateMachine::apply($from, $action);
                $this->fail("Expected InvalidTradeTransitionException for {$from->value} + {$action}");
            } catch (InvalidTradeTransitionException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_dispute_and_cancel_edges(): void
    {
        $this->assertSame(TradeStatus::DISPUTED, SafeDealStateMachine::apply(TradeStatus::FUNDED, 'open_dispute'));
        $this->assertSame(TradeStatus::SETTLEMENT_ELIGIBLE, SafeDealStateMachine::apply(TradeStatus::DISPUTED, 'resolve_release'));
        $this->assertSame(TradeStatus::REFUNDED, SafeDealStateMachine::apply(TradeStatus::DISPUTED, 'resolve_return'));
        $this->assertSame(TradeStatus::REFUNDED, SafeDealStateMachine::apply(TradeStatus::FUNDED, 'cancel'));
        $this->assertSame(TradeStatus::CANCELLED, SafeDealStateMachine::apply(TradeStatus::DRAFT, 'cancel'));
        $this->assertSame(TradeStatus::CANCELLED, SafeDealStateMachine::apply(TradeStatus::RISK_ASSESSED, 'reject_risk'));
    }

    public function test_risk_assessment_is_deterministic_and_rejects_high_scores(): void
    {
        $service = app(TradeRiskAssessmentService::class);

        $high = $service->assess([
            'amount'                => '60000.0000',
            'buyer_level'           => 'basic',
            'seller_level'          => 'basic',
            'documents_approved'    => 0,
            'documents_required'    => 1,
            'has_delivery_deadline' => false,
            'milestone_count'       => 0,
            'prior_disputes'        => true,
        ]);

        // 30 + 20 + 25 + 10 + 10 + 15 = 110 -> capped at 100, rejected.
        $this->assertSame(100, $high['score']);
        $this->assertSame(RiskLevel::REJECTED, $high['level']);
        $this->assertTrue($high['rejected']);

        $low = $service->assess([
            'amount'                => '100.0000',
            'buyer_level'           => 'enhanced',
            'seller_level'          => 'enhanced',
            'documents_approved'    => 1,
            'documents_required'    => 1,
            'has_delivery_deadline' => true,
            'milestone_count'       => 1,
            'prior_disputes'        => false,
        ]);

        $this->assertSame(0, $low['score']);
        $this->assertSame(RiskLevel::LOW, $low['level']);
        $this->assertFalse($low['rejected']);
        $this->assertFalse($low['requires_review']);

        // Same input, same output.
        $this->assertSame($low, $service->assess([
            'amount'                => '100.0000',
            'buyer_level'           => 'enhanced',
            'seller_level'          => 'enhanced',
            'documents_approved'    => 1,
            'documents_required'    => 1,
            'has_delivery_deadline' => true,
            'milestone_count'       => 1,
            'prior_disputes'        => false,
        ]));
    }
}
