<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Services;

use App\Domain\SafeDeal\Aggregates\SafeDealTradeAggregate;
use App\Domain\SafeDeal\Enums\DisputeResolution;
use App\Domain\SafeDeal\Enums\DocumentStatus;
use App\Domain\SafeDeal\Enums\MilestoneStatus;
use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Enums\VerificationStatus;
use App\Domain\SafeDeal\Events\AgreementAccepted;
use App\Domain\SafeDeal\Events\ApprovalRecorded;
use App\Domain\SafeDeal\Events\CounterpartyVerified;
use App\Domain\SafeDeal\Events\DeliveryConfirmed;
use App\Domain\SafeDeal\Events\DisputeOpened;
use App\Domain\SafeDeal\Events\DisputeResolved;
use App\Domain\SafeDeal\Events\DocumentReviewed;
use App\Domain\SafeDeal\Events\DocumentSubmitted;
use App\Domain\SafeDeal\Events\FundingAuthorized;
use App\Domain\SafeDeal\Events\MilestoneCompleted;
use App\Domain\SafeDeal\Events\PaymentConfirmed;
use App\Domain\SafeDeal\Events\RefundIssued;
use App\Domain\SafeDeal\Events\RiskAssessed;
use App\Domain\SafeDeal\Events\SettlementEligible;
use App\Domain\SafeDeal\Events\TradeCancelled;
use App\Domain\SafeDeal\Events\TradeCreated;
use App\Domain\SafeDeal\Events\TradeSettled;
use App\Domain\SafeDeal\Exceptions\InvalidTradeTransitionException;
use App\Domain\SafeDeal\Exceptions\SafeDealException;
use App\Domain\SafeDeal\Models\SafeDealDispute;
use App\Domain\SafeDeal\Models\SafeDealDocument;
use App\Domain\SafeDeal\Models\SafeDealMilestone;
use App\Domain\SafeDeal\Models\SafeDealTrade;
use App\Domain\SafeDeal\Repositories\SafeDealTradeRepository;
use App\Domain\SafeDeal\StateMachines\SafeDealStateMachine;
use App\Domain\SafeDeal\ValueObjects\TradeAmount;
use App\Domain\SafeDeal\ValueObjects\TradeTerms;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * SafeDeal lifecycle orchestrator.
 *
 * One method per lifecycle step; every method loads the trade through the
 * aggregate, enforces who may act, and lets the state machine reject any
 * out-of-order step. Money moves only inside confirmPayment / settle /
 * cancel / resolveDispute, and only through SafeDealSettlementService
 * (LedgerService::postIdempotent / reverse).
 */
class SafeDealService
{
    public function __construct(
        private readonly SafeDealTradeRepository $repository,
        private readonly CounterpartyVerificationService $verification,
        private readonly TradeRiskAssessmentService $risk,
        private readonly SafeDealSettlementService $settlement,
    ) {
    }

    /**
     * @param array<int, array{title: string, amount: string}> $milestones
     * @param array<string, mixed> $terms
     */
    public function createTrade(
        string $buyerId,
        string $sellerId,
        string $amount,
        string $currency,
        array $terms,
        array $milestones,
        array $metadata = [],
    ): SafeDealTrade {
        if ($buyerId === $sellerId) {
            throw new SafeDealException('Buyer and seller must be different counterparties.');
        }

        $money = TradeAmount::fromMixed($amount, $currency);
        $tradeTerms = TradeTerms::fromArray($terms);

        if ($tradeTerms->description === '') {
            throw new SafeDealException('Trade terms must include a description.');
        }

        if (count($milestones) === 0) {
            throw new SafeDealException('At least one milestone is required.');
        }

        $total = '0.0000';

        foreach ($milestones as $milestone) {
            $mAmount = TradeAmount::fromMixed((string) ($milestone['amount'] ?? '0'), $currency);
            $total = bcadd($total, $mAmount->amount, 4);
        }

        if (bccomp($total, $money->amount, 4) !== 0) {
            throw new SafeDealException('Milestone amounts must sum to the trade amount.');
        }

        $trade = SafeDealTrade::create([
            'trade_id'            => 'safedeal-' . Str::uuid()->toString(),
            'buyer_id'            => $buyerId,
            'seller_id'           => $sellerId,
            'amount'              => $money->amount,
            'currency'            => $money->currency,
            'status'              => TradeStatus::DRAFT,
            'buyer_verification'  => VerificationStatus::UNVERIFIED,
            'seller_verification' => VerificationStatus::UNVERIFIED,
            'terms'               => $tradeTerms->toArray(),
            'terms_version'       => 1,
            'metadata'            => $metadata,
        ]);

        foreach ($milestones as $milestone) {
            SafeDealMilestone::create([
                'trade_id' => $trade->id,
                'title'    => (string) $milestone['title'],
                'amount'   => bcadd((string) $milestone['amount'], '0', 4),
                'status'   => MilestoneStatus::PENDING,
            ]);
        }

        SafeDealTradeAggregate::fromModel($trade)->record(
            new TradeCreated($trade->trade_id, [
                'buyer_id'  => $buyerId,
                'seller_id' => $sellerId,
                'amount'    => $money->amount,
                'currency'  => $money->currency,
            ])
        );

        return $trade->fresh();
    }

    public function requestVerification(string $tradeId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        $aggregate->transition('request_verification', new CounterpartyVerified($trade->trade_id, [
            'step' => 'verification_requested',
        ]));

        return $aggregate->trade();
    }

    /**
     * Record a counterparty verification decision. When both parties are
     * verified (and the enhanced gate passes for the amount) the trade
     * moves to VERIFIED.
     */
    public function verifyCounterparty(string $tradeId, string $partyId, string $kycReference, string $level = CounterpartyVerificationService::BASIC): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        if (! $trade->isParty($partyId)) {
            throw new SafeDealException('Only a trade counterparty can be verified for this trade.');
        }

        $result = $this->verification->verify($partyId, $kycReference, $level);

        $attributes = $trade->isBuyer($partyId)
            ? ['buyer_verification' => VerificationStatus::VERIFIED, 'buyer_verification_level' => $level]
            : ['seller_verification' => VerificationStatus::VERIFIED, 'seller_verification_level' => $level];

        // Keep the KYC reference out of the fillable snapshot: store it in metadata.
        $metadata = $trade->metadata ?? [];
        $metadata['verification_references'][$partyId] = $kycReference;
        $attributes['metadata'] = $metadata;

        if ($trade->status === TradeStatus::DRAFT) {
            $aggregate->transition('request_verification', new CounterpartyVerified($trade->trade_id, [
                'party_id' => $partyId,
                'level'    => $level,
                'step'     => 'verification_requested',
            ]), $attributes);
        } else {
            $aggregate->record(new CounterpartyVerified($trade->trade_id, [
                'party_id' => $partyId,
                'level'    => $level,
            ]));
            $trade->fill($attributes);
            $trade->save();
        }

        $trade = $aggregate->trade();

        if (
            $trade->status === TradeStatus::PENDING_VERIFICATION
            && $trade->bothPartiesVerified()
            && $this->verification->passesGate([
                'buyer_verification'  => $trade->buyer_verification,
                'seller_verification' => $trade->seller_verification,
                'buyer_level'         => $trade->buyer_verification_level,
                'seller_level'        => $trade->seller_verification_level,
            ], (string) $trade->amount)
        ) {
            $aggregate->transition('mark_verified', new CounterpartyVerified($trade->trade_id, [
                'step' => 'both_parties_verified',
            ]));
        }

        return $aggregate->trade();
    }

    public function submitAgreement(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $aggregate->transition('submit_agreement', new AgreementAccepted($trade->trade_id, [
            'step'     => 'agreement_submitted',
            'actor_id' => $actorId,
        ]));

        return $aggregate->trade();
    }

    public function acceptAgreement(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $aggregate->transition('accept_agreement', new AgreementAccepted($trade->trade_id, [
            'actor_id'      => $actorId,
            'terms_version' => $trade->terms_version,
        ]));

        return $aggregate->trade();
    }

    public function rejectAgreement(string $tradeId, string $actorId, string $reason): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $aggregate->transition('reject_agreement', new AgreementAccepted($trade->trade_id, [
            'actor_id' => $actorId,
            'rejected' => true,
            'reason'   => $reason,
        ]));

        return $aggregate->trade();
    }

    /**
     * Terms may only change while AGREED; the change bumps the version and
     * sends the trade back to AGREEMENT_PENDING for re-acceptance.
     *
     * @param array<string, mixed> $terms
     */
    public function updateTerms(string $tradeId, string $actorId, array $terms): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $newTerms = TradeTerms::fromArray($terms, $trade->terms_version + 1);

        $aggregate->transition('update_terms', new AgreementAccepted($trade->trade_id, [
            'actor_id'      => $actorId,
            'terms_version' => $newTerms->version,
            'step'          => 'terms_updated',
        ]), [
            'terms'         => $newTerms->toArray(),
            'terms_version' => $newTerms->version,
        ]);

        return $aggregate->trade();
    }

    public function submitDocument(string $tradeId, string $actorId, string $type, string $reference): SafeDealDocument
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        if ($trade->status === TradeStatus::AGREED) {
            $aggregate->transition('submit_documents', new DocumentSubmitted($trade->trade_id, [
                'actor_id' => $actorId,
                'type'     => $type,
            ]));
            $trade = $aggregate->trade();
        } elseif ($trade->status !== TradeStatus::DOCUMENTS_PENDING) {
            throw new SafeDealException("Documents cannot be submitted while the trade is {$trade->status->value}.");
        }

        $document = SafeDealDocument::create([
            'trade_id'     => $trade->id,
            'type'         => $type,
            'reference'    => $reference,
            'submitted_by' => $actorId,
            'status'       => DocumentStatus::SUBMITTED,
        ]);

        $aggregate->record(new DocumentSubmitted($trade->trade_id, [
            'document_id' => $document->id,
            'type'        => $type,
        ]));

        return $document;
    }

    public function reviewDocument(string $tradeId, int $documentId, string $reviewerId, bool $approve, ?string $note = null): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        // Phase 8: document review was unauthenticated at the service
        // layer — any authenticated user could approve/reject any trade's
        // documents. Only a counterparty or an admin may review.
        $this->assertPartyOrAdmin($trade, $reviewerId);

        $document = SafeDealDocument::where('trade_id', $trade->id)->findOrFail($documentId);

        $document->update([
            'status'      => $approve ? DocumentStatus::APPROVED : DocumentStatus::REJECTED,
            'reviewed_by' => $reviewerId,
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);

        $aggregate->record(new DocumentReviewed($trade->trade_id, [
            'document_id' => $document->id,
            'type'        => $document->type,
            'approved'    => $approve,
            'reviewer_id' => $reviewerId,
        ]));

        $trade = $aggregate->trade();

        if ($trade->status === TradeStatus::DOCUMENTS_PENDING && $this->allRequiredDocumentsApproved($trade)) {
            $aggregate->transition('review_documents', new DocumentReviewed($trade->trade_id, [
                'step' => 'all_documents_approved',
            ]));
        }

        return $aggregate->trade();
    }

    public function assessRisk(string $tradeId, string $assessorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        // Phase 8: risk assessment was callable by any authenticated user
        // and a high-risk outcome CANCELS the trade — it must be a party
        // or an admin act, never a stranger's.
        $this->assertPartyOrAdmin($trade, $assessorId);

        $required = $this->requiredDocumentTypes($trade);

        $assessment = $this->risk->assess([
            'amount'                => (string) $trade->amount,
            'buyer_level'           => $trade->buyer_verification_level,
            'seller_level'          => $trade->seller_verification_level,
            'documents_approved'    => SafeDealDocument::where('trade_id', $trade->id)->where('status', DocumentStatus::APPROVED)->count(),
            'documents_required'    => count($required),
            'has_delivery_deadline' => ! empty($trade->terms['delivery_deadline'] ?? null),
            'milestone_count'       => $trade->milestones()->count(),
            'prior_disputes'        => SafeDealDispute::where('trade_id', $trade->id)->exists(),
        ]);

        if ($assessment['rejected']) {
            // High risk: the trade is cancelled, not silently parked.
            $aggregate->transition('reject_risk', new RiskAssessed($trade->trade_id, [
                'score'       => $assessment['score'],
                'level'       => $assessment['level']->value,
                'factors'     => $assessment['factors'],
                'assessor_id' => $assessorId,
                'rejected'    => true,
            ]), [
                'risk_score'          => $assessment['score'],
                'risk_level'          => $assessment['level'],
                'risk_factors'        => $assessment['factors'],
                'cancelled_at'        => now(),
                'cancellation_reason' => 'risk_rejected',
            ]);

            return $aggregate->trade();
        }

        $aggregate->transition('assess_risk', new RiskAssessed($trade->trade_id, [
            'score'           => $assessment['score'],
            'level'           => $assessment['level']->value,
            'factors'         => $assessment['factors'],
            'assessor_id'     => $assessorId,
            'requires_review' => $assessment['requires_review'],
        ]), [
            'risk_score'   => $assessment['score'],
            'risk_level'   => $assessment['level'],
            'risk_factors' => $assessment['factors'],
        ]);

        return $aggregate->trade();
    }

    public function authorizeFunding(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        if (! $trade->isBuyer($actorId)) {
            throw new SafeDealException('Only the buyer can authorize funding.');
        }

        $aggregate->transition('authorize_funding', new FundingAuthorized($trade->trade_id, [
            'actor_id' => $actorId,
            'amount'   => (string) $trade->amount,
            'currency' => $trade->currency,
        ]));

        return $aggregate->trade();
    }

    /**
     * Payment / funding authorization: posts the funding entry through the
     * approved ledger path. SIMULATED escrow: virtual ledger accounts only.
     */
    public function confirmPayment(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        if (! $trade->isBuyer($actorId)) {
            throw new SafeDealException('Only the buyer can confirm payment.');
        }

        // Guard BEFORE any ledger posting: a rejected transition must never
        // leave a funding entry behind.
        if ($trade->status !== TradeStatus::FUNDING_PENDING) {
            throw new InvalidTradeTransitionException("Payment cannot be confirmed while the trade is {$trade->status->value}.");
        }

        $entry = $this->settlement->fund($trade->fresh());

        $aggregate->transition('confirm_payment', new PaymentConfirmed($trade->trade_id, [
            'actor_id'         => $actorId,
            'journal_entry_id' => $entry->id,
            'amount'           => (string) $trade->amount,
            'currency'         => $trade->currency,
        ]), ['funded_at' => now()]);

        return $aggregate->trade();
    }

    public function startMilestones(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $aggregate->transition('start_milestones', new MilestoneCompleted($trade->trade_id, [
            'step'     => 'milestones_started',
            'actor_id' => $actorId,
        ]));

        return $aggregate->trade();
    }

    public function completeMilestone(string $tradeId, int $milestoneId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        if (! $trade->isSeller($actorId)) {
            throw new SafeDealException('Only the seller can complete a milestone.');
        }

        $milestone = SafeDealMilestone::where('trade_id', $trade->id)->findOrFail($milestoneId);

        if ($milestone->status === MilestoneStatus::COMPLETED) {
            throw new SafeDealException('Milestone is already completed.');
        }

        $milestone->update(['status' => MilestoneStatus::COMPLETED, 'completed_at' => now()]);

        $aggregate->record(new MilestoneCompleted($trade->trade_id, [
            'milestone_id' => $milestone->id,
            'title'        => $milestone->title,
            'actor_id'     => $actorId,
        ]));

        $trade = $aggregate->trade();

        if ($trade->status === TradeStatus::IN_PROGRESS && $trade->allMilestonesCompleted()) {
            $aggregate->transition('all_milestones_done', new MilestoneCompleted($trade->trade_id, [
                'step' => 'all_milestones_completed',
            ]));
        }

        return $aggregate->trade();
    }

    public function confirmDelivery(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        if (! $trade->isBuyer($actorId)) {
            throw new SafeDealException('Only the buyer can confirm delivery.');
        }

        if (! $trade->allMilestonesCompleted()) {
            throw new SafeDealException('Delivery cannot be confirmed while milestones are incomplete.');
        }

        $aggregate->transition('confirm_delivery', new DeliveryConfirmed($trade->trade_id, [
            'actor_id' => $actorId,
        ]), ['delivered_at' => now()]);

        return $aggregate->trade();
    }

    /**
     * Buyer + seller approval. The first approval moves the trade to
     * APPROVALS_PENDING; the second makes it SETTLEMENT_ELIGIBLE.
     */
    public function recordApproval(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        if ($trade->isBuyer($actorId) && $trade->buyer_approved_at !== null) {
            throw new SafeDealException('Buyer has already approved this trade.');
        }

        if ($trade->isSeller($actorId) && $trade->seller_approved_at !== null) {
            throw new SafeDealException('Seller has already approved this trade.');
        }

        $attributes = $trade->isBuyer($actorId)
            ? ['buyer_approved_at' => now()]
            : ['seller_approved_at' => now()];

        if ($trade->status === TradeStatus::DELIVERED) {
            $aggregate->transition('record_approval', new ApprovalRecorded($trade->trade_id, [
                'actor_id' => $actorId,
                'role'     => $trade->isBuyer($actorId) ? 'buyer' : 'seller',
            ]), $attributes);
        } elseif ($trade->status === TradeStatus::APPROVALS_PENDING) {
            $trade->fill($attributes);
            $trade->save();
            $aggregate->record(new ApprovalRecorded($trade->trade_id, [
                'actor_id' => $actorId,
                'role'     => $trade->isBuyer($actorId) ? 'buyer' : 'seller',
            ]));
        } else {
            throw new SafeDealException("Approvals cannot be recorded while the trade is {$trade->status->value}.");
        }

        $trade = $aggregate->trade();

        if ($trade->status === TradeStatus::APPROVALS_PENDING && $trade->bothApproved()) {
            $aggregate->transition('all_approved', new SettlementEligible($trade->trade_id, [
                'buyer_approved_at'  => $trade->buyer_approved_at?->toIso8601String(),
                'seller_approved_at' => $trade->seller_approved_at?->toIso8601String(),
            ]));
        }

        return $aggregate->trade();
    }

    /**
     * Settlement: escrow -> seller (or the split shares after a split
     * dispute resolution). Only from SETTLEMENT_ELIGIBLE.
     */
    public function settle(string $tradeId, string $actorId): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        if ($trade->status !== TradeStatus::SETTLEMENT_ELIGIBLE) {
            throw new InvalidTradeTransitionException("Trade cannot be settled while it is {$trade->status->value}.");
        }

        $split = null;
        $metadata = $trade->metadata ?? [];

        if (isset($metadata['split'])) {
            $split = [
                'buyer_share'  => (string) $metadata['split']['buyer_share'],
                'seller_share' => (string) $metadata['split']['seller_share'],
            ];
        }

        $entries = $this->settlement->settle($trade->fresh(), $split);

        $aggregate->transition('settle', new TradeSettled($trade->trade_id, [
            'actor_id'          => $actorId,
            'journal_entry_ids' => array_map(fn ($e) => $e->id, $entries),
            'split'             => $split,
        ]), ['settled_at' => now()]);

        return $aggregate->trade();
    }

    public function openDispute(string $tradeId, string $actorId, string $reason, array $evidence = []): SafeDealDispute
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        $dispute = SafeDealDispute::create([
            'trade_id'   => $trade->id,
            'dispute_id' => 'safedeal-dispute-' . Str::uuid()->toString(),
            'opened_by'  => $actorId,
            'reason'     => $reason,
            'evidence'   => $evidence,
            'status'     => 'open',
        ]);

        $aggregate->transition('open_dispute', new DisputeOpened($trade->trade_id, [
            'dispute_id' => $dispute->dispute_id,
            'opened_by'  => $actorId,
            'reason'     => $reason,
        ]));

        return $dispute;
    }

    /**
     * Dispute resolution. release_to_seller -> settlement_eligible;
     * return_to_buyer -> refunded (funding entry reversed);
     * split -> settlement_eligible with the split stored for settle().
     */
    public function resolveDispute(string $tradeId, string $disputeId, string $resolverId, DisputeResolution $resolution, ?array $split = null): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();

        // Phase 8: dispute resolution had NO actor check at all — any
        // authenticated user could resolve any trade's dispute, including
        // RELEASE_TO_SELLER / RETURN_TO_BUYER, which move money via the
        // settlement refund. It is an admin-operator act only (see
        // SafeDealTradePolicy::resolveDispute). Fail closed when the
        // resolver is not a known admin user.
        $resolver = User::find($resolverId);

        if ($resolver === null || ! $resolver->hasRole('admin')) {
            throw new SafeDealException('Only an admin operator can resolve a dispute.');
        }

        $dispute = SafeDealDispute::where('trade_id', $trade->id)
            ->where('dispute_id', $disputeId)
            ->firstOrFail();

        if (! $dispute->isOpen()) {
            throw new SafeDealException('Dispute is already resolved.');
        }

        if ($resolution === DisputeResolution::SPLIT) {
            if ($split === null) {
                throw new SafeDealException('A split resolution requires buyer_share and seller_share.');
            }

            $buyerShare = bcadd((string) $split['buyer_share'], '0', 4);
            $sellerShare = bcadd((string) $split['seller_share'], '0', 4);

            if (bccomp(bcadd($buyerShare, $sellerShare, 4), (string) $trade->amount, 4) !== 0) {
                throw new SafeDealException('Split shares must sum to the trade amount.');
            }

            $metadata = $trade->metadata ?? [];
            $metadata['split'] = ['buyer_share' => $buyerShare, 'seller_share' => $sellerShare];
            $trade->metadata = $metadata;
            $trade->save();
        }

        $dispute->update([
            'status'       => 'resolved',
            'resolution'   => $resolution,
            'resolved_by'  => $resolverId,
            'resolved_at'  => now(),
            'seller_share' => $split !== null ? ['buyer_share' => (string) $split['buyer_share'], 'seller_share' => (string) $split['seller_share']] : null,
        ]);

        $action = match ($resolution) {
            DisputeResolution::RELEASE_TO_SELLER => 'resolve_release',
            DisputeResolution::RETURN_TO_BUYER   => 'resolve_return',
            DisputeResolution::SPLIT             => 'resolve_split',
        };

        if ($resolution === DisputeResolution::RETURN_TO_BUYER) {
            $reversal = $this->settlement->refund($trade->fresh(), "dispute {$dispute->dispute_id} resolved return_to_buyer");

            // Recorded BEFORE the terminal transition: the final audit
            // record is written by that transition and must include it.
            $aggregate->record(new RefundIssued($trade->trade_id, [
                'dispute_id'        => $dispute->dispute_id,
                'reversal_entry_id' => $reversal?->id,
                'amount'            => (string) $trade->amount,
            ]));

            $aggregate->transition($action, new DisputeResolved($trade->trade_id, [
                'dispute_id'        => $dispute->dispute_id,
                'resolution'        => $resolution->value,
                'resolved_by'       => $resolverId,
                'reversal_entry_id' => $reversal?->id,
            ]), ['refunded_at' => now()]);

            return $aggregate->trade();
        }

        $aggregate->transition($action, new DisputeResolved($trade->trade_id, [
            'dispute_id'  => $dispute->dispute_id,
            'resolution'  => $resolution->value,
            'resolved_by' => $resolverId,
        ]));

        return $aggregate->trade();
    }

    /**
     * Cancellation. Before funding: plain CANCELLED. After funding: the
     * funding entry is reversed and the trade ends REFUNDED.
     */
    public function cancel(string $tradeId, string $actorId, string $reason): SafeDealTrade
    {
        $aggregate = SafeDealTradeAggregate::retrieve($tradeId);
        $trade = $aggregate->trade();
        $this->assertParty($trade, $actorId);

        if (! SafeDealStateMachine::can($trade->status, 'cancel')) {
            throw new InvalidTradeTransitionException("Trade cannot be cancelled while it is {$trade->status->value}.");
        }

        $wasFunded = $trade->status->isFundedStage();

        if ($wasFunded) {
            $reversal = $this->settlement->refund($trade->fresh(), "cancelled by {$actorId}: {$reason}");

            // Recorded BEFORE the terminal transition: the final audit
            // record is written by that transition and must include it.
            $aggregate->record(new RefundIssued($trade->trade_id, [
                'actor_id'          => $actorId,
                'reason'            => $reason,
                'reversal_entry_id' => $reversal?->id,
                'amount'            => (string) $trade->amount,
            ]));

            $aggregate->transition('cancel', new TradeCancelled($trade->trade_id, [
                'actor_id'          => $actorId,
                'reason'            => $reason,
                'reversal_entry_id' => $reversal?->id,
            ]), [
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
                'refunded_at'         => now(),
            ]);

            return $aggregate->trade();
        }

        $aggregate->transition('cancel', new TradeCancelled($trade->trade_id, [
            'actor_id' => $actorId,
            'reason'   => $reason,
        ]), [
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        return $aggregate->trade();
    }

    public function getTrade(string $tradeId): SafeDealTrade
    {
        return $this->repository->findOrFail($tradeId);
    }

    /** @return list<string> */
    private function requiredDocumentTypes(SafeDealTrade $trade): array
    {
        $required = $trade->terms['conditions']['required_documents'] ?? ['agreement_copy'];

        return array_values(array_map(strval(...), (array) $required));
    }

    private function allRequiredDocumentsApproved(SafeDealTrade $trade): bool
    {
        $required = $this->requiredDocumentTypes($trade);

        if (count($required) === 0) {
            return SafeDealDocument::where('trade_id', $trade->id)->where('status', DocumentStatus::APPROVED)->exists();
        }

        foreach ($required as $type) {
            $approved = SafeDealDocument::where('trade_id', $trade->id)
                ->where('type', $type)
                ->where('status', DocumentStatus::APPROVED)
                ->exists();

            if (! $approved) {
                return false;
            }
        }

        return true;
    }

    private function assertParty(SafeDealTrade $trade, string $actorId): void
    {
        if (! $trade->isParty($actorId)) {
            throw new SafeDealException('Actor is not a counterparty of this trade.');
        }
    }

    /**
     * Phase 8: party-or-admin gate for reviewer/assessor acts. A missing
     * user row fails closed — an unknown actor is never treated as an admin.
     */
    private function assertPartyOrAdmin(SafeDealTrade $trade, string $actorId): void
    {
        if ($trade->isParty($actorId)) {
            return;
        }

        $actor = User::find($actorId);

        if ($actor === null || ! $actor->hasRole('admin')) {
            throw new SafeDealException('Actor is not a counterparty of this trade.');
        }
    }
}
