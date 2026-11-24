<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Payment\Events\TransferRequiresManualReview;
use App\Domain\Payment\Events\TransferReversed;
use App\Domain\Payment\Exceptions\ProviderTimeoutException;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Internal transfer orchestration with ledger reservation.
 *
 * Flow (no provider — internal transfer):
 *   validate → post guarded entry sender → recipient → successful + receipt
 *
 * Flow (with a provider call):
 *   validate → reserve (guarded entry sender → hold account) → call provider
 *     - success    → settle (entry hold → recipient) → successful + receipt
 *     - definitive failure → reverse the reservation entry → failed
 *     - timeout / ambiguous / unexpected provider error → UNKNOWN:
 *       the hold is NEVER released and the transfer is NEVER auto-failed.
 *       Resolution happens only via resolveUnknown() (status query,
 *       webhook, or manual review).
 *
 * Idempotency: a replayed idempotency key returns the original
 * PaymentTransaction without re-executing anything. The key is UNIQUE in
 * the database, so concurrent duplicates race on the constraint.
 *
 * Every money movement goes through LedgerService::post() /
 * LedgerService::reverse() — this service never writes journal rows
 * directly.
 */
final class PaymentTransferService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransferValidationService $validation,
        private readonly PaymentReceiptService $receipts,
    ) {
    }

    /**
     * Execute a transfer.
     *
     * @param callable(PaymentTransaction): array{status: string, provider_reference?: string} $providerCall
     *        Optional provider step. Must return a canonical status (or a
     *        legacy one mapped by TransactionState::fromLegacy), or throw
     *        ProviderTimeoutException on timeout / ambiguous outcome.
     */
    public function transfer(
        string $senderAccountCode,
        string $recipientAccountCode,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        ?callable $providerCall = null,
        ?string $reference = null,
        ?string $holdAccountCode = null,
    ): PaymentTransaction {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty.');
        }

        $existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $validated = $this->validation->validate(
            $senderAccountCode,
            $recipientAccountCode,
            $amountMinor,
            $currency,
            $reference,
        );

        $currency = strtoupper($currency);
        $amount = $validated['amount'];
        $fee = $validated['fee'];

        // Fast pre-check so an obviously unaffordable transfer fails before
        // any transaction row is created. The hard enforcement is still the
        // guarded ledger post below, which serialises concurrent writers.
        $senderBalance = $this->ledger->getBalance($senderAccountCode);

        if (DecimalMath::cmp($senderBalance['amount'], $amount->decimal, 4) < 0) {
            throw new InvalidArgumentException(
                "Insufficient funds in '{$senderAccountCode}': balance {$senderBalance['amount']} {$currency}, transfer needs {$amount->decimal}."
            );
        }

        $transaction = $this->createTransaction(
            $validated['reference'],
            $idempotencyKey,
            $amountMinor,
            $currency,
            $senderAccountCode,
            $recipientAccountCode,
            $fee,
        );

        if ($providerCall === null) {
            $entry = $this->ledger->postGuarded(
                "Transfer {$transaction->reference}",
                [
                    ['account_code' => $senderAccountCode, 'debit' => '0.0000', 'credit' => $amount->decimal],
                    ['account_code' => $recipientAccountCode, 'debit' => $amount->decimal, 'credit' => '0.0000'],
                ],
                [$senderAccountCode],
                'Payment',
                $transaction->reference,
            );

            $this->mark($transaction, TransactionState::SUCCESSFUL, [
                'settlement_entry_id' => $entry->id,
                'completed_at'        => now()->toIso8601String(),
            ]);
            $this->receipts->issue($transaction->refresh(), $entry->id);

            return $transaction->refresh();
        }

        return $this->transferWithProvider($transaction, $providerCall, $holdAccountCode, $amount, $senderAccountCode, $recipientAccountCode, $currency);
    }

    /**
     * Resolve an `unknown` (or `requires-manual-review`) transfer using
     * evidence: a provider status query, a webhook, or a human decision.
     * Only a successful outcome settles; only a failed outcome reverses.
     */
    public function resolveUnknown(PaymentTransaction $transaction, TransactionState $outcome, string $reason): PaymentTransaction
    {
        $current = TransactionState::fromLegacy((string) $transaction->status);

        if (! in_array($current, [TransactionState::UNKNOWN, TransactionState::REQUIRES_MANUAL_REVIEW], true)) {
            throw new InvalidArgumentException(
                "Only unknown / requires-manual-review transfers can be resolved; current state is {$current->value}."
            );
        }

        if ($outcome === TransactionState::SUCCESSFUL) {
            return $this->settle($transaction, $reason);
        }

        if ($outcome === TransactionState::FAILED) {
            return $this->failTransfer($transaction, $reason);
        }

        if ($outcome === TransactionState::REVERSED) {
            return $this->reverseFailedTransfer($transaction, $reason);
        }

        throw new InvalidArgumentException("Cannot resolve an unknown transfer to {$outcome->value}; evidence must say successful or failed.");
    }

    /**
     * Flag a transfer for manual review (admin hook event, no UI here).
     * The transfer keeps its hold; nothing is settled or released.
     */
    public function flagForManualReview(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        $previous = TransactionState::fromLegacy((string) $transaction->status);

        if ($previous->isTerminal()) {
            throw new InvalidArgumentException("Terminal transfer {$transaction->reference} cannot be flagged for review.");
        }

        $this->mark($transaction, TransactionState::REQUIRES_MANUAL_REVIEW, []);

        event(new TransferRequiresManualReview($transaction->refresh(), $reason, $previous));

        return $transaction->refresh();
    }

    /**
     * Failed-transfer recovery: reverse the reservation entry (mirror
     * entry — the original is never edited) and mark the transfer
     * REVERSED. Safe to call twice: the second call is a no-op returning
     * the already-recovered transaction.
     */
    public function reverseFailedTransfer(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        $current = TransactionState::fromLegacy((string) $transaction->status);

        if (in_array($current, [TransactionState::REVERSED, TransactionState::FAILED], true)) {
            // Recovery already happened (failed transfers keep their
            // FAILED status with the reversal link; see failTransfer()).
            return $transaction;
        }

        if ($current === TransactionState::SUCCESSFUL) {
            throw new InvalidArgumentException("Successful transfer {$transaction->reference} cannot be reversed this way; post a new correcting transfer instead.");
        }

        $reversalEntryId = $this->reverseReservation($transaction, $reason);

        $this->mark($transaction, TransactionState::REVERSED, [
            'reversal_entry_id' => $reversalEntryId,
            'failed_reason'     => $reason,
        ]);

        $transaction->update(['reversed_by' => $reversalEntryId]);

        event(new TransferReversed($transaction->refresh(), (string) $reversalEntryId, $reason));

        return $transaction->refresh();
    }

    /**
     * Reverse the reservation entry for a transfer, if one exists and is
     * still posted. Returns the reversal entry id, or null when there was
     * nothing to reverse (e.g. it was already reversed by a concurrent
     * recovery — the money is back with the sender either way).
     */
    private function reverseReservation(PaymentTransaction $transaction, string $reason): ?string
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $reservationEntryId = $metadata['reservation_entry_id'] ?? null;

        if (! is_string($reservationEntryId) || $reservationEntryId === '') {
            return null;
        }

        try {
            return $this->ledger->reverse($reservationEntryId, $reason)->id;
        } catch (RuntimeException $e) {
            Log::warning('Transfer reversal: reservation already reversed', [
                'reference' => $transaction->reference,
                'error'     => $e->getMessage(),
            ]);

            return $metadata['reversal_entry_id'] ?? null;
        }
    }

    /**
     * @param callable(PaymentTransaction): array{status: string, provider_reference?: string} $providerCall
     */
    private function transferWithProvider(
        PaymentTransaction $transaction,
        callable $providerCall,
        ?string $holdAccountCode,
        \App\Domain\Ledger\ValueObjects\MoneyAmount $amount,
        string $senderAccountCode,
        string $recipientAccountCode,
        string $currency,
    ): PaymentTransaction {
        $holdAccountCode ??= (string) config('payment_rails.transfers.hold_account_code', 'HOLD');

        // 1. Reserve: sender → hold, guarded so the sender can never go
        //    negative. The provider is only called after the reservation
        //    commits, so provider latency cannot create a double-spend.
        $reservation = $this->ledger->postGuarded(
            "Transfer reservation {$transaction->reference}",
            [
                ['account_code' => $senderAccountCode, 'debit' => '0.0000', 'credit' => $amount->decimal],
                ['account_code' => $holdAccountCode, 'debit' => $amount->decimal, 'credit' => '0.0000'],
            ],
            [$senderAccountCode],
            'Payment',
            $transaction->reference,
        );

        $this->mark($transaction, TransactionState::PROCESSING, [
            'reservation_entry_id' => $reservation->id,
            'hold_account_code'    => $holdAccountCode,
        ]);

        // 2. Provider call — never inside a DB transaction.
        try {
            $result = $providerCall($transaction->refresh());
        } catch (ProviderTimeoutException $e) {
            return $this->markUnknown($transaction, 'Provider timeout: ' . $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Transfer provider call failed ambiguously — marking unknown, hold kept', [
                'reference' => $transaction->reference,
                'error'     => $e->getMessage(),
            ]);

            return $this->markUnknown($transaction, 'Ambiguous provider outcome: ' . $e->getMessage());
        }

        $providerReference = $result['provider_reference'] ?? null;

        if (is_string($providerReference) && $providerReference !== '') {
            $this->mark($transaction, TransactionState::PROCESSING, ['provider_reference' => $providerReference]);
            $transaction->update(['external_reference' => $providerReference]);
        }

        $outcome = TransactionState::fromLegacy((string) ($result['status'] ?? ''));

        return match ($outcome) {
            TransactionState::SUCCESSFUL => $this->settle($transaction->refresh(), 'Provider confirmed success'),
            TransactionState::FAILED     => $this->failTransfer($transaction->refresh(), 'Provider reported failure'),
            default                      => $this->markUnknown($transaction->refresh(), "Provider returned unclassifiable status '{$result['status']}'"),
        };
    }

    private function failTransfer(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        // Definitive failure: reverse the reservation (money back to the
        // sender) and record FAILED as the terminal state, keeping the
        // reversal link. The reservation reversal is a mirror entry.
        $reversalEntryId = $this->reverseReservation($transaction, $reason);

        $this->mark($transaction, TransactionState::FAILED, [
            'reversal_entry_id' => $reversalEntryId,
            'failed_reason'     => $reason,
        ]);

        $transaction->update(['reversed_by' => $reversalEntryId, 'failed_at' => now()]);

        event(new TransferReversed($transaction->refresh(), (string) $reversalEntryId, $reason));

        return $transaction->refresh();
    }

    private function settle(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $holdAccountCode = (string) ($metadata['hold_account_code'] ?? config('payment_rails.transfers.hold_account_code', 'HOLD'));
        $recipientAccountCode = (string) ($metadata['recipient_account_code'] ?? '');
        $currency = strtoupper((string) $transaction->currency);

        if ($recipientAccountCode === '') {
            throw new RuntimeException("Cannot settle transfer {$transaction->reference}: recipient missing from metadata.");
        }

        $amount = \App\Domain\Ledger\ValueObjects\MoneyAmount::fromMinorUnits((int) $transaction->amount, $currency);

        $entry = $this->ledger->postGuarded(
            "Transfer settlement {$transaction->reference}",
            [
                ['account_code' => $holdAccountCode, 'debit' => '0.0000', 'credit' => $amount->decimal],
                ['account_code' => $recipientAccountCode, 'debit' => $amount->decimal, 'credit' => '0.0000'],
            ],
            [$holdAccountCode],
            'Payment',
            $transaction->reference,
        );

        $this->mark($transaction, TransactionState::SUCCESSFUL, [
            'settlement_entry_id' => $entry->id,
            'completed_at'        => now()->toIso8601String(),
        ]);
        $this->receipts->issue($transaction->refresh(), $entry->id);

        return $transaction->refresh();
    }

    private function markUnknown(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        $previous = TransactionState::fromLegacy((string) $transaction->status);

        $transaction->update([
            'status'           => TransactionState::UNKNOWN->value,
            'unknown_since_at' => now(),
            'failed_reason'    => $reason,
        ]);

        // The hold stays in place: nothing is released, nothing is failed.
        event(new TransferRequiresManualReview($transaction->refresh(), $reason, $previous));

        return $transaction->refresh();
    }

    /** @param array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int} $fee */
    private function createTransaction(
        string $reference,
        string $idempotencyKey,
        int $amountMinor,
        string $currency,
        string $senderAccountCode,
        string $recipientAccountCode,
        array $fee,
    ): PaymentTransaction {
        $attributes = [
            'aggregate_uuid' => Str::uuid()->toString(),
            // Ledger-code transfers are not tied to an Account-domain
            // aggregate; the parties are recorded in metadata instead.
            'account_uuid'    => Str::uuid()->toString(),
            'type'            => 'transfer',
            'status'          => TransactionState::INITIATED->value,
            'amount'          => $amountMinor,
            'currency'        => $currency,
            'reference'       => $reference,
            'idempotency_key' => $idempotencyKey,
            'metadata'        => [
                'sender_account_code'    => $senderAccountCode,
                'recipient_account_code' => $recipientAccountCode,
                'fee_minor'              => $fee['fee_minor'],
                'fee_decimal'            => $fee['fee_decimal'],
            ],
            'initiated_at' => now(),
        ];

        try {
            return DB::transaction(fn (): PaymentTransaction => PaymentTransaction::create($attributes));
        } catch (QueryException $e) {
            // Concurrent duplicate (same idempotency key or same reference):
            // return the original instead of creating a second transfer.
            $original = PaymentTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->orWhere('reference', $reference)
                ->first();

            if ($original !== null) {
                return $original;
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function mark(PaymentTransaction $transaction, TransactionState $state, array $metadata): void
    {
        $merged = array_merge(is_array($transaction->metadata) ? $transaction->metadata : [], $metadata);

        $updates = ['status' => $state->value, 'metadata' => $merged];

        if ($state === TransactionState::SUCCESSFUL) {
            $updates['completed_at'] = now();
        }

        if ($state === TransactionState::FAILED) {
            $updates['failed_at'] = now();
        }

        $transaction->update($updates);
    }
}
