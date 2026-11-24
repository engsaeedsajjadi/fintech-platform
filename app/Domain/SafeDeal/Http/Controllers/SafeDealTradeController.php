<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Http\Controllers;

use App\Domain\SafeDeal\Enums\DisputeResolution;
use App\Domain\SafeDeal\Http\Requests\CreateTradeRequest;
use App\Domain\SafeDeal\Http\Requests\OpenDisputeRequest;
use App\Domain\SafeDeal\Http\Requests\ResolveDisputeRequest;
use App\Domain\SafeDeal\Http\Requests\SubmitDocumentRequest;
use App\Domain\SafeDeal\Policies\SafeDealTradePolicy;
use App\Domain\SafeDeal\Services\CounterpartyVerificationService;
use App\Domain\SafeDeal\Services\SafeDealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SafeDealTradeController
{
    public function __construct(
        private readonly SafeDealService $service,
        private readonly SafeDealTradePolicy $policy = new SafeDealTradePolicy(),
    ) {
    }

    public function store(CreateTradeRequest $request): JsonResponse
    {
        $trade = $this->service->createTrade(
            (string) $request->user()->id,
            (string) $request->validated()['seller_id'],
            (string) $request->validated()['amount'],
            strtoupper((string) $request->validated()['currency']),
            (array) $request->validated()['terms'],
            (array) $request->validated()['milestones'],
        );

        return response()->json(['data' => $this->present($trade)], 201);
    }

    public function show(Request $request, string $tradeId): JsonResponse
    {
        $trade = $this->service->getTrade($tradeId);
        $this->authorizeView($request, $trade);

        return response()->json(['data' => $this->present($trade)]);
    }

    public function verify(Request $request, string $tradeId): JsonResponse
    {
        $request->validate([
            'kyc_reference' => ['required', 'string', 'max:255'],
            'level'         => ['sometimes', 'in:basic,enhanced'],
        ]);

        $trade = $this->service->verifyCounterparty(
            $tradeId,
            (string) $request->user()->id,
            (string) $request->input('kyc_reference'),
            (string) $request->input('level', CounterpartyVerificationService::BASIC),
        );

        return response()->json(['data' => $this->present($trade)]);
    }

    public function submitAgreement(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->submitAgreement($tradeId, (string) $request->user()->id)
        )]);
    }

    public function acceptAgreement(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->acceptAgreement($tradeId, (string) $request->user()->id)
        )]);
    }

    public function submitDocument(SubmitDocumentRequest $request, string $tradeId): JsonResponse
    {
        $document = $this->service->submitDocument(
            $tradeId,
            (string) $request->user()->id,
            (string) $request->validated()['type'],
            (string) $request->validated()['reference'],
        );

        return response()->json(['data' => $document->toArray()], 201);
    }

    public function reviewDocument(Request $request, string $tradeId, int $documentId): JsonResponse
    {
        $request->validate(['approve' => ['required', 'boolean'], 'note' => ['sometimes', 'nullable', 'string']]);

        // Phase 8: only a counterparty or an admin may approve/reject a
        // document (the service enforces the same rule; this returns a
        // clean 403 instead of a 500 from the domain exception).
        $this->authorizeReviewDocuments($request, $tradeId);

        return response()->json(['data' => $this->present(
            $this->service->reviewDocument($tradeId, $documentId, (string) $request->user()->id, (bool) $request->input('approve'), $request->input('note'))
        )]);
    }

    public function assessRisk(Request $request, string $tradeId): JsonResponse
    {
        // Phase 8: a high-risk assessment cancels the trade, so strangers
        // must not be able to trigger it.
        $this->authorizeAssessRisk($request, $tradeId);

        return response()->json(['data' => $this->present(
            $this->service->assessRisk($tradeId, (string) $request->user()->id)
        )]);
    }

    public function authorizeFunding(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->authorizeFunding($tradeId, (string) $request->user()->id)
        )]);
    }

    public function confirmPayment(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->confirmPayment($tradeId, (string) $request->user()->id)
        )]);
    }

    public function startMilestones(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->startMilestones($tradeId, (string) $request->user()->id)
        )]);
    }

    public function completeMilestone(Request $request, string $tradeId, int $milestoneId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->completeMilestone($tradeId, $milestoneId, (string) $request->user()->id)
        )]);
    }

    public function confirmDelivery(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->confirmDelivery($tradeId, (string) $request->user()->id)
        )]);
    }

    public function approve(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->recordApproval($tradeId, (string) $request->user()->id)
        )]);
    }

    public function settle(Request $request, string $tradeId): JsonResponse
    {
        return response()->json(['data' => $this->present(
            $this->service->settle($tradeId, (string) $request->user()->id)
        )]);
    }

    public function openDispute(OpenDisputeRequest $request, string $tradeId): JsonResponse
    {
        $dispute = $this->service->openDispute(
            $tradeId,
            (string) $request->user()->id,
            (string) $request->validated()['reason'],
            (array) ($request->validated()['evidence'] ?? []),
        );

        return response()->json(['data' => $dispute->toArray()], 201);
    }

    public function resolveDispute(ResolveDisputeRequest $request, string $tradeId, string $disputeId): JsonResponse
    {
        // Phase 8: dispute resolution moves money (release / refund /
        // split) and is an admin-operator act only — a party resolving
        // their own dispute, let alone a stranger, was previously
        // possible. The service enforces the same rule; this returns a
        // clean 403 instead of a 500 from the domain exception.
        $this->authorizeResolveDispute($request, $tradeId);

        $trade = $this->service->resolveDispute(
            $tradeId,
            $disputeId,
            (string) $request->user()->id,
            DisputeResolution::from((string) $request->validated()['resolution']),
            $request->validated()['split'] ?? null,
        );

        return response()->json(['data' => $this->present($trade)]);
    }

    public function cancel(Request $request, string $tradeId): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->present(
            $this->service->cancel($tradeId, (string) $request->user()->id, (string) $request->input('reason'))
        )]);
    }

    private function authorizeView(Request $request, \App\Domain\SafeDeal\Models\SafeDealTrade $trade): void
    {
        if (! $trade->isParty((string) $request->user()->id) && ! $request->user()->hasRole('admin')) {
            abort(403, 'You are not a counterparty of this trade.');
        }
    }

    private function authorizeReviewDocuments(Request $request, string $tradeId): void
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $trade = $this->service->getTrade($tradeId);

        if (! $this->policy->reviewDocuments($actor, $trade)) {
            abort(403, 'Only a counterparty or an admin can review documents for this trade.');
        }
    }

    private function authorizeAssessRisk(Request $request, string $tradeId): void
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $trade = $this->service->getTrade($tradeId);

        if (! $this->policy->assessRisk($actor, $trade)) {
            abort(403, 'Only a counterparty or an admin can assess risk for this trade.');
        }
    }

    private function authorizeResolveDispute(Request $request, string $tradeId): void
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $trade = $this->service->getTrade($tradeId);

        if (! $this->policy->resolveDispute($actor, $trade)) {
            abort(403, 'Only an admin operator can resolve a dispute.');
        }
    }

    /** @return array<string, mixed> */
    private function present(\App\Domain\SafeDeal\Models\SafeDealTrade $trade): array
    {
        $trade->loadMissing(['milestones', 'documents', 'disputes', 'auditRecord']);

        return [
            'trade_id'            => $trade->trade_id,
            'buyer_id'            => $trade->buyer_id,
            'seller_id'           => $trade->seller_id,
            'amount'              => (string) $trade->amount,
            'currency'            => $trade->currency,
            'status'              => $trade->status->value,
            'buyer_verification'  => $trade->buyer_verification->value,
            'seller_verification' => $trade->seller_verification->value,
            'risk_score'          => $trade->risk_score,
            'risk_level'          => $trade->risk_level?->value,
            'terms_version'       => $trade->terms_version,
            'milestones'          => $trade->milestones->toArray(),
            'documents'           => $trade->documents->toArray(),
            'disputes'            => $trade->disputes->toArray(),
            'audit_record'        => $trade->auditRecord?->toArray(),
        ];
    }
}
