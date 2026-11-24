<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Workflows;

use App\Domain\SafeDeal\Models\SafeDealTrade;
use App\Domain\SafeDeal\Services\SafeDealService;
use Throwable;

/**
 * Deterministic step-runner for the happy-path SafeDeal lifecycle.
 *
 * This is NOT the Laravel Workflow package (AgentProtocol's EscrowWorkflow
 * uses it, with its durable workflow tables): SafeDeal steps are fast,
 * synchronous business steps, so the workflow is a plain ordered runner
 * with compensation — if any step throws after funding, the trade is
 * cancelled and the funding entry is reversed (refund), so a failed run
 * can never leave money held without a trade in a matching state.
 *
 * @return array{trade: SafeDealTrade, steps: list<string>}
 */
class SafeDealTradeWorkflow
{
    public function __construct(
        private readonly SafeDealService $service,
    ) {
    }

    /**
     * @param array<int, array{title: string, amount: string}> $milestones
     * @param array<string, mixed> $terms
     * @param list<int> $milestoneIds
     */
    public function runToSettlement(
        string $buyerId,
        string $sellerId,
        string $amount,
        string $currency,
        array $terms,
        array $milestones,
        string $buyerKycReference,
        string $sellerKycReference,
        array $milestoneIds = [],
    ): array {
        $completed = [];
        $trade = null;

        try {
            $trade = $this->service->createTrade($buyerId, $sellerId, $amount, $currency, $terms, $milestones);
            $completed[] = 'create';

            $this->service->verifyCounterparty($trade->trade_id, $buyerId, $buyerKycReference, 'enhanced');
            $this->service->verifyCounterparty($trade->trade_id, $sellerId, $sellerKycReference, 'enhanced');
            $completed[] = 'verify';

            $this->service->submitAgreement($trade->trade_id, $buyerId);
            $this->service->acceptAgreement($trade->trade_id, $sellerId);
            $completed[] = 'agree';

            foreach ((array) ($terms['conditions']['required_documents'] ?? ['agreement_copy']) as $type) {
                $document = $this->service->submitDocument($trade->trade_id, $buyerId, (string) $type, "ref-{$type}");
                $this->service->reviewDocument($trade->trade_id, $document->id, $sellerId, true);
            }

            $completed[] = 'documents';

            $this->service->assessRisk($trade->trade_id, $sellerId);
            $completed[] = 'risk';

            $this->service->authorizeFunding($trade->trade_id, $buyerId);
            $this->service->confirmPayment($trade->trade_id, $buyerId);
            $completed[] = 'fund';

            $this->service->startMilestones($trade->trade_id, $sellerId);

            $ids = $milestoneIds !== [] ? $milestoneIds : $trade->fresh()->milestones()->pluck('id')->all();

            foreach ($ids as $milestoneId) {
                $this->service->completeMilestone($trade->trade_id, (int) $milestoneId, $sellerId);
            }

            $completed[] = 'milestones';

            $this->service->confirmDelivery($trade->trade_id, $buyerId);
            $completed[] = 'delivery';

            $this->service->recordApproval($trade->trade_id, $buyerId);
            $this->service->recordApproval($trade->trade_id, $sellerId);
            $completed[] = 'approvals';

            $trade = $this->service->settle($trade->trade_id, $buyerId);
            $completed[] = 'settle';

            return ['trade' => $trade, 'steps' => $completed];
        } catch (Throwable $e) {
            // Compensation: refund anything already funded so no money is
            // left held by a failed run.
            if ($trade !== null && in_array('fund', $completed, true)) {
                try {
                    $this->service->cancel($trade->trade_id, $buyerId, 'workflow compensation after step failure: ' . $e->getMessage());
                } catch (Throwable) {
                    // Compensation failure is reported via the original error;
                    // the trade stays visible for operator reconciliation.
                }
            }

            throw $e;
        }
    }
}
