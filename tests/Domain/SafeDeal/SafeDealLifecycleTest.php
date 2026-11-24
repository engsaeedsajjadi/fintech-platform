<?php

declare(strict_types=1);

namespace Tests\Domain\SafeDeal;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Exceptions\InvalidTradeTransitionException;
use App\Domain\SafeDeal\Exceptions\SafeDealException;
use App\Domain\SafeDeal\Models\SafeDealAuditRecord;
use App\Domain\SafeDeal\Models\SafeDealEventRecord;
use App\Domain\SafeDeal\Services\SafeDealService;
use App\Domain\SafeDeal\Workflows\SafeDealTradeWorkflow;
use Tests\TestCase;

class SafeDealLifecycleTest extends TestCase
{
    private SafeDealService $service;

    private string $buyerId;

    private string $sellerId;

    private \App\Models\User $admin;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SafeDealService::class);
        $this->buyerId = (string) $this->user->id;
        $this->sellerId = (string) $this->business_user->id;

        // Phase 8: counterparty verification now requires approved
        // platform KYC (enhanced level for the 'enhanced' verifications
        // below), so the fixtures carry it.
        $this->user->update(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);
        $this->business_user->update(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);

        // Phase 8: dispute resolution is admin-only; the resolver id must
        // belong to a real admin user (previously the tests passed a
        // placeholder string instead of a user id).
        $this->admin = \App\Models\User::factory()->create();
        $this->admin->assignRole('admin');
        $this->adminId = (string) $this->admin->id;
        $this->seedBuyerLedgerBalance();
    }

    private function seedBuyerLedgerBalance(): void
    {
        $buyerCode = "SAFEDEAL:BUYER:{$this->buyerId}:USD";
        $fundingCode = 'SAFEDEAL:TEST-FUNDING:USD';

        LedgerAccount::firstOrCreate(
            ['code' => $buyerCode],
            ['name' => 'SafeDeal test buyer balance', 'type' => 'liability', 'currency' => 'USD', 'is_active' => true],
        );
        LedgerAccount::firstOrCreate(
            ['code' => $fundingCode],
            ['name' => 'SafeDeal test funding source', 'type' => 'asset', 'currency' => 'USD', 'is_active' => true],
        );

        app(LedgerService::class)->post(
            'SafeDeal test opening balance',
            [
                ['account_code' => $fundingCode, 'debit' => '1000.0000', 'credit' => '0.0000', 'currency' => 'USD'],
                ['account_code' => $buyerCode, 'debit' => '0.0000', 'credit' => '1000.0000', 'currency' => 'USD'],
            ],
            'SafeDealTest',
            "buyer-fixture:{$this->buyerId}",
            "safedeal-test-funding:{$this->buyerId}",
        );
    }

    /** @return array{trade_id: string, buyer_id: string, seller_id: string} */
    private function createTrade(string $amount = '100.0000'): array
    {
        $trade = $this->service->createTrade(
            $this->buyerId,
            $this->sellerId,
            $amount,
            'USD',
            [
                'description'       => 'Website development with escrow',
                'conditions'        => ['required_documents' => ['agreement_copy']],
                'delivery_deadline' => now()->addDays(30)->toDateString(),
            ],
            [['title' => 'Delivery', 'amount' => $amount]],
        );

        return ['trade_id' => $trade->trade_id, 'buyer_id' => $this->buyerId, 'seller_id' => $this->sellerId];
    }

    /** Drive a trade to FUNDED and return its trade_id. */
    private function fundTrade(string $amount = '100.0000'): string
    {
        $ctx = $this->createTrade($amount);
        $id = $ctx['trade_id'];

        $this->service->verifyCounterparty($id, $this->buyerId, 'kyc-buyer-1', 'enhanced');
        $this->service->verifyCounterparty($id, $this->sellerId, 'kyc-seller-1', 'enhanced');
        $this->service->submitAgreement($id, $this->buyerId);
        $this->service->acceptAgreement($id, $this->sellerId);

        $document = $this->service->submitDocument($id, $this->buyerId, 'agreement_copy', 'doc-ref-1');
        $this->service->reviewDocument($id, $document->id, $this->sellerId, true);

        $this->service->assessRisk($id, $this->sellerId);
        $this->service->authorizeFunding($id, $this->buyerId);
        $trade = $this->service->confirmPayment($id, $this->buyerId);

        $this->assertSame(TradeStatus::FUNDED, $trade->status);

        return $id;
    }

    public function test_funding_refuses_to_overdraw_the_buyers_ledger_balance(): void
    {
        $before = JournalEntry::where('description', 'like', 'SafeDeal funding %')->count();

        try {
            $this->fundTrade('1001.0000');
            $this->fail('SafeDeal must reject funding above the buyer ledger balance.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('overdraw ledger account', $exception->getMessage());
        }

        $this->assertSame($before, JournalEntry::where('description', 'like', 'SafeDeal funding %')->count());
    }

    public function test_full_lifecycle_to_settlement_with_ledger_and_audit(): void
    {
        $id = $this->fundTrade();

        $trade = $this->service->startMilestones($id, $this->sellerId);
        $this->assertSame(TradeStatus::IN_PROGRESS, $trade->status);

        $milestone = $trade->milestones()->firstOrFail();
        $trade = $this->service->completeMilestone($id, $milestone->id, $this->sellerId);
        $this->assertSame(TradeStatus::DELIVERY_PENDING, $trade->status);

        $trade = $this->service->confirmDelivery($id, $this->buyerId);
        $this->assertSame(TradeStatus::DELIVERED, $trade->status);

        $trade = $this->service->recordApproval($id, $this->buyerId);
        $this->assertSame(TradeStatus::APPROVALS_PENDING, $trade->status);

        $trade = $this->service->recordApproval($id, $this->sellerId);
        $this->assertSame(TradeStatus::SETTLEMENT_ELIGIBLE, $trade->status);

        $entriesBefore = JournalEntry::count();
        $trade = $this->service->settle($id, $this->buyerId);
        $this->assertSame(TradeStatus::SETTLED, $trade->status);
        $this->assertSame($entriesBefore + 1, JournalEntry::count());

        // Funding + settlement entries are both recorded on the trade.
        $this->assertCount(2, $trade->metadata['ledger_entry_ids']);

        // Final audit record: exactly one, terminal, with a hashed history.
        $audit = SafeDealAuditRecord::where('trade_id', $trade->id)->firstOrFail();
        $this->assertSame('settled', $audit->final_status);
        $this->assertSame(64, strlen($audit->event_history_hash));
        $this->assertContains('trade.settled', $audit->event_history);
        $this->assertContains('trade.created', $audit->event_history);

        // Append-only event log covers the whole lifecycle.
        $this->assertGreaterThanOrEqual(12, SafeDealEventRecord::where('trade_id', $trade->id)->count());
    }

    public function test_workflow_runs_happy_path_to_settlement(): void
    {
        $workflow = app(SafeDealTradeWorkflow::class);

        $result = $workflow->runToSettlement(
            $this->buyerId,
            $this->sellerId,
            '250.0000',
            'USD',
            [
                'description'       => 'Workflow trade',
                'conditions'        => ['required_documents' => ['agreement_copy']],
                'delivery_deadline' => now()->addDays(10)->toDateString(),
            ],
            [['title' => 'Delivery', 'amount' => '250.0000']],
            'kyc-buyer-wf',
            'kyc-seller-wf',
        );

        $this->assertSame(TradeStatus::SETTLED, $result['trade']->status);
        $this->assertSame(
            ['create', 'verify', 'agree', 'documents', 'risk', 'fund', 'milestones', 'delivery', 'approvals', 'settle'],
            $result['steps']
        );
    }

    public function test_dispute_resolved_return_to_buyer_refunds_via_ledger_reversal(): void
    {
        $id = $this->fundTrade();

        $dispute = $this->service->openDispute($id, $this->buyerId, 'Goods not as described', ['photo-1']);
        $trade = $this->service->getTrade($id);
        $this->assertSame(TradeStatus::DISPUTED, $trade->status);

        $trade = $this->service->resolveDispute($id, $dispute->dispute_id, $this->adminId, \App\Domain\SafeDeal\Enums\DisputeResolution::RETURN_TO_BUYER);
        $this->assertSame(TradeStatus::REFUNDED, $trade->status);
        $this->assertNotNull($trade->refunded_at);

        // The funding entry was reversed (mirror entry), never edited.
        $fundingId = $trade->metadata['funding_entry_id'];
        $funding = JournalEntry::findOrFail($fundingId);
        $this->assertSame('reversed', $funding->status->value);
        $this->assertNotNull($funding->reversed_by);

        $audit = SafeDealAuditRecord::where('trade_id', $trade->id)->firstOrFail();
        $this->assertSame('refunded', $audit->final_status);
        $this->assertContains('trade.dispute_opened', $audit->event_history);
        $this->assertContains('trade.refund_issued', $audit->event_history);
    }

    public function test_dispute_resolved_release_to_seller_settles(): void
    {
        $id = $this->fundTrade();

        $dispute = $this->service->openDispute($id, $this->sellerId, 'Buyer unresponsive', []);
        $trade = $this->service->resolveDispute($id, $dispute->dispute_id, $this->adminId, \App\Domain\SafeDeal\Enums\DisputeResolution::RELEASE_TO_SELLER);
        $this->assertSame(TradeStatus::SETTLEMENT_ELIGIBLE, $trade->status);

        $trade = $this->service->settle($id, $this->sellerId);
        $this->assertSame(TradeStatus::SETTLED, $trade->status);
    }

    public function test_dispute_split_settles_with_both_shares(): void
    {
        $id = $this->fundTrade('200.0000');

        $dispute = $this->service->openDispute($id, $this->buyerId, 'Partial delivery', []);
        $trade = $this->service->resolveDispute(
            $id,
            $dispute->dispute_id,
            $this->adminId,
            \App\Domain\SafeDeal\Enums\DisputeResolution::SPLIT,
            ['buyer_share' => '80.0000', 'seller_share' => '120.0000'],
        );
        $this->assertSame(TradeStatus::SETTLEMENT_ELIGIBLE, $trade->status);

        $trade = $this->service->settle($id, $this->buyerId);
        $this->assertSame(TradeStatus::SETTLED, $trade->status);
        // Funding + 2 split legs.
        $this->assertCount(3, $trade->metadata['ledger_entry_ids']);
    }

    public function test_cancel_before_funding_is_plain_cancellation_without_ledger(): void
    {
        $ctx = $this->createTrade();
        $entriesBefore = JournalEntry::count();

        $trade = $this->service->cancel($ctx['trade_id'], $this->buyerId, 'Changed my mind');

        $this->assertSame(TradeStatus::CANCELLED, $trade->status);
        $this->assertSame($entriesBefore, JournalEntry::count());
        $this->assertNotNull(SafeDealAuditRecord::where('trade_id', $trade->id)->first());
    }

    public function test_cancel_after_funding_refunds(): void
    {
        $id = $this->fundTrade();

        $trade = $this->service->cancel($id, $this->buyerId, 'No longer needed');

        $this->assertSame(TradeStatus::REFUNDED, $trade->status);
        $funding = JournalEntry::findOrFail($trade->metadata['funding_entry_id']);
        $this->assertSame('reversed', $funding->status->value);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $ctx = $this->createTrade();
        $id = $ctx['trade_id'];

        // Settle straight from DRAFT.
        $this->expectException(InvalidTradeTransitionException::class);
        $this->service->settle($id, $this->buyerId);
    }

    public function test_settle_twice_is_rejected(): void
    {
        $id = $this->fundTrade();

        $trade = $this->service->getTrade($id);
        $milestone = $trade->milestones()->firstOrFail();

        $this->service->startMilestones($id, $this->sellerId);
        $this->service->completeMilestone($id, $milestone->id, $this->sellerId);
        $this->service->confirmDelivery($id, $this->buyerId);
        $this->service->recordApproval($id, $this->buyerId);
        $this->service->recordApproval($id, $this->sellerId);
        $this->service->settle($id, $this->buyerId);

        $this->expectException(InvalidTradeTransitionException::class);
        $this->service->settle($id, $this->buyerId);
    }

    public function test_delivery_requires_completed_milestones(): void
    {
        $id = $this->fundTrade();
        $this->service->startMilestones($id, $this->sellerId);

        $this->expectException(SafeDealException::class);
        $this->service->confirmDelivery($id, $this->buyerId);
    }

    public function test_funding_is_idempotent_on_retry(): void
    {
        $id = $this->fundTrade();
        $trade = $this->service->getTrade($id);

        // Re-posting the same funding key returns the original entry.
        $settlement = app(\App\Domain\SafeDeal\Services\SafeDealSettlementService::class);
        $first = $settlement->fund($trade->fresh());
        $second = $settlement->fund($trade->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('description', 'SafeDeal funding ' . $trade->trade_id)->count());
    }

    public function test_milestones_must_sum_to_amount(): void
    {
        $this->expectException(SafeDealException::class);
        $this->service->createTrade(
            $this->buyerId,
            $this->sellerId,
            '100.0000',
            'USD',
            ['description' => 'Bad milestones'],
            [['title' => 'Part', 'amount' => '60.0000']],
        );
    }

    public function test_risk_assessment_scores_large_trade_but_allows_it(): void
    {
        // 60000 with enhanced verification and a deadline: only the
        // amount>=50000 rule fires (30 points) — LOW, trade proceeds.
        // (Basic verification at this amount is correctly fail-closed by
        // the enhanced-verification gate before agreement; the >= 70
        // rejection rule itself is covered in SafeDealStateMachineTest.)
        $trade = $this->service->createTrade(
            $this->buyerId,
            $this->sellerId,
            '60000.0000',
            'USD',
            [
                'description'       => 'Large trade, enhanced verification',
                'conditions'        => ['required_documents' => ['agreement_copy']],
                'delivery_deadline' => now()->addDays(30)->toDateString(),
            ],
            [['title' => 'Delivery', 'amount' => '60000.0000']],
        );

        $this->service->verifyCounterparty($trade->trade_id, $this->buyerId, 'kyc-b1', 'enhanced');
        $this->service->verifyCounterparty($trade->trade_id, $this->sellerId, 'kyc-s1', 'enhanced');
        $this->service->submitAgreement($trade->trade_id, $this->buyerId);
        $this->service->acceptAgreement($trade->trade_id, $this->sellerId);
        $document = $this->service->submitDocument($trade->trade_id, $this->buyerId, 'agreement_copy', 'doc-1');
        $this->service->reviewDocument($trade->trade_id, $document->id, $this->sellerId, true);

        $trade = $this->service->assessRisk($trade->trade_id, $this->sellerId);

        $this->assertSame(TradeStatus::RISK_ASSESSED, $trade->status);
        $this->assertSame(30, $trade->risk_score);
        $this->assertSame('low', $trade->risk_level->value);
    }
}
