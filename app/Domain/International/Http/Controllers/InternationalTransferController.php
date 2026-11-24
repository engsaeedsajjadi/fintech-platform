<?php

declare(strict_types=1);

namespace App\Domain\International\Http\Controllers;

use App\Domain\International\Exceptions\ExchangeRateUnavailableException;
use App\Domain\International\Exceptions\InternationalRailNotAvailableException;
use App\Domain\International\Exceptions\InternationalTransferException;
use App\Domain\International\Exceptions\InvalidTransferTransitionException;
use App\Domain\International\Models\InternationalTransfer;
use App\Domain\International\Policies\InternationalTransferPolicy;
use App\Domain\International\Services\InternationalTransferService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * User + operator API for international P2P transfers.
 *
 * Owner endpoints: create (idempotency key REQUIRED, via the
 * `Idempotency-Key` header), show, list, submit, process, reverse,
 * history. Operator endpoints (compliance role, enforced by route
 * middleware): screening start/decision, external dispatch, settle.
 */
final class InternationalTransferController extends Controller
{
    public function __construct(
        private readonly InternationalTransferService $transfers,
        private readonly InternationalTransferPolicy $policy = new InternationalTransferPolicy(),
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = InternationalTransfer::where('user_id', $user->id)->orderByDesc('created_at');

        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20)->through(fn (InternationalTransfer $t): array => $this->present($t)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'MISSING_IDEMPOTENCY_KEY', 'message' => 'The Idempotency-Key header is required to create an international transfer.'],
            ], 422);
        }

        $validated = $request->validate([
            'international_account_id' => ['required', 'string'],
            'counterparty_id'          => ['required', 'string'],
            'beneficiary_id'           => ['required', 'string'],
            'amount_minor'             => ['required', 'integer', 'min:1'],
            'currency'                 => ['required', 'string', 'size:3'],
            'destination_currency'     => ['required', 'string', 'size:3'],
            'purpose'                  => ['required', 'string'],
            'purpose_detail'           => ['nullable', 'string', 'max:500'],
            'rail'                     => ['required', 'string'],
            'exchange_quotation_id'    => ['nullable', 'string'],
            'charge_bearer'            => ['nullable', 'string', 'in:our,sha,ben'],
            'value_date'               => ['nullable', 'date'],
            'remittance_information'   => ['nullable', 'string', 'max:140'],
        ]);

        try {
            $transfer = $this->transfers->createTransfer($user, $validated, $idempotencyKey);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }

        return response()->json(['success' => true, 'data' => $this->present($transfer)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $transfer = InternationalTransfer::find($id);

        if ($transfer === null) {
            return $this->notFound();
        }

        if (! $this->policy->view($user, $transfer)) {
            return $this->forbidden();
        }

        return response()->json(['success' => true, 'data' => $this->present($transfer, true)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (InternationalTransfer $t, User $u) => $this->transfers->submit($t, $u));
    }

    public function process(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (InternationalTransfer $t, User $u) => $this->transfers->process($t, $u));
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($request, $id, fn (InternationalTransfer $t, User $u) => $this->transfers->reverse($t, $u, $validated['reason']));
    }

    public function beginScreening(Request $request, string $id): JsonResponse
    {
        return $this->operatorAct($request, $id, fn (InternationalTransfer $t, User $u) => $this->transfers->beginScreening($t, $u));
    }

    public function recordScreeningDecision(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:clear,reject'],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        return $this->operatorAct(
            $request,
            $id,
            fn (InternationalTransfer $t, User $u) => $this->transfers->recordScreeningDecision($t, $u, $validated['decision'], $validated['reason'] ?? null),
        );
    }

    public function dispatch(Request $request, string $id): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $transfer = InternationalTransfer::find($id);

        if ($transfer === null) {
            return $this->notFound();
        }

        try {
            $this->transfers->attemptExternalDispatch($transfer, $operator);
        } catch (InternationalRailNotAvailableException $e) {
            // Fail-closed, by design: the transfer was moved to `failed`
            // and its ledger legs reversed. Report it as such.
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'RAIL_NOT_AVAILABLE', 'message' => $e->getMessage()],
                'data'    => $this->present($transfer->refresh()),
            ], 422);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['external_reference' => ['required', 'string', 'max:255']]);

        return $this->operatorAct(
            $request,
            $id,
            fn (InternationalTransfer $t, User $u) => $this->transfers->settle($t, $u, $validated['external_reference']),
        );
    }

    public function history(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $transfer = InternationalTransfer::with(['statusHistory', 'auditLogs'])->find($id);

        if ($transfer === null) {
            return $this->notFound();
        }

        if (! $this->policy->view($user, $transfer)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'transfer'       => $this->present($transfer),
                'status_history' => $transfer->statusHistory->map(fn ($h): array => [
                    'from_status'   => $h->from_status,
                    'to_status'     => $h->to_status,
                    'actor_user_id' => $h->actor_user_id,
                    'reason'        => $h->reason,
                    'created_at'    => $h->created_at?->toIso8601String(),
                ])->all(),
                'audit_log' => $transfer->auditLogs->map(fn ($a): array => [
                    'action'     => $a->action,
                    'user_id'    => $a->user_id,
                    'details'    => $a->details,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /** @param callable(InternationalTransfer, User): InternationalTransfer $action */
    private function act(Request $request, string $id, callable $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $transfer = InternationalTransfer::find($id);

        if ($transfer === null) {
            return $this->notFound();
        }

        if (! $this->policy->manage($user, $transfer)) {
            return $this->forbidden();
        }

        try {
            return response()->json(['success' => true, 'data' => $this->present($action($transfer, $user))]);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    /** @param callable(InternationalTransfer, User): InternationalTransfer $action */
    private function operatorAct(Request $request, string $id, callable $action): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $transfer = InternationalTransfer::find($id);

        if ($transfer === null) {
            return $this->notFound();
        }

        try {
            return response()->json(['success' => true, 'data' => $this->present($action($transfer, $operator))]);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    private function domainError(Throwable $e): JsonResponse
    {
        if ($e instanceof AuthorizationException) {
            return $this->forbidden($e->getMessage());
        }

        if ($e instanceof InternationalRailNotAvailableException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'RAIL_NOT_AVAILABLE', 'message' => $e->getMessage()],
            ], 422);
        }

        if ($e instanceof InvalidTransferTransitionException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'INVALID_TRANSITION', 'message' => $e->getMessage()],
            ], 422);
        }

        if ($e instanceof ExchangeRateUnavailableException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'QUOTATION_REQUIRED', 'message' => $e->getMessage()],
            ], 422);
        }

        if ($e instanceof InternationalTransferException) {
            $isConflict = str_contains($e->getMessage(), 'different payload');

            return response()->json([
                'success' => false,
                'error'   => ['code' => $isConflict ? 'IDEMPOTENCY_CONFLICT' : 'TRANSFER_ERROR', 'message' => $e->getMessage()],
            ], $isConflict ? 409 : 422);
        }

        if ($e instanceof InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        throw $e;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => 'NOT_FOUND', 'message' => 'Transfer not found.'],
        ], 404);
    }

    private function forbidden(string $message = 'Transfer does not belong to this user.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => 'FORBIDDEN', 'message' => $message],
        ], 403);
    }

    /** @return array<string, mixed> */
    private function present(InternationalTransfer $transfer, bool $verbose = false): array
    {
        $data = [
            'id'                          => $transfer->id,
            'reference'                   => $transfer->reference,
            'end_to_end_reference'        => $transfer->end_to_end_reference,
            'external_reference'          => $transfer->external_reference,
            'status'                      => $transfer->status->value,
            'screening_status'            => $transfer->screening_status,
            'amount_minor'                => (int) $transfer->amount_minor,
            'fee_minor'                   => (int) $transfer->fee_minor,
            'currency'                    => $transfer->currency,
            'destination_currency'        => $transfer->destination_currency,
            'destination_amount_minor'    => (int) $transfer->destination_amount_minor,
            'purpose'                     => $transfer->purpose->value,
            'rail'                        => $transfer->rail->value,
            'rail_enabled'                => $transfer->rail->isEnabled(),
            'failure_reason'              => $transfer->failure_reason,
            'journal_entry_id'            => $transfer->journal_entry_id,
            'fee_journal_entry_id'        => $transfer->fee_journal_entry_id,
            'settlement_journal_entry_id' => $transfer->settlement_journal_entry_id,
            'created_at'                  => $transfer->created_at?->toIso8601String(),
            'updated_at'                  => $transfer->updated_at?->toIso8601String(),
        ];

        if ($verbose) {
            $data['international_account_id'] = $transfer->international_account_id;
            $data['counterparty_id'] = $transfer->counterparty_id;
            $data['beneficiary_id'] = $transfer->beneficiary_id;
            $data['exchange_quotation_id'] = $transfer->exchange_quotation_id;
            $data['idempotency_key'] = $transfer->idempotency_key;
        }

        return $data;
    }
}
