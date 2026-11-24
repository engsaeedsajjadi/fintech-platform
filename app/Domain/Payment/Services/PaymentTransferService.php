<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
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
 * Fee model (Phase 4.2): the transfer fee is carved OUT of the amount
 * (TransferFeeCalculator's net_minor), never charged on top and never left
 * as metadata-only. The principal entry moves the NET amount
 * sender → recipient, and the fee moves in a SEPARATE, idempotent entry
 * (`transfer-fee:{reference}`) from the sender (or the hold, on the
 * provider path) to the `PAYMENT:FEES:{currency}` control account. The
 * sender's total outflow is still the gross amount, the recipient receives
 * the net amount, and the fee value is conserved in the ledger instead of
 * vanishing. The control account is an asset holding collected fees;
 * recognising it as fee income is a finance month-end sweep, not an
 * automatic posting (see postFeeEntry()).
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
            // Principal entry moves the NET amount (gross minus fee); the
            // fee itself moves in a separate entry (postFeeEntry). With a
            // zero fee, net == gross and this is exactly the old entry.
            $net = MoneyAmount::fromMinorUnits($fee['net_minor'], $currency);

            $entry = $this->ledger->postGuarded(
                "Transfer {$transaction->reference}",
                [
                    ['account_code' => $senderAccountCode, 'debit' => '0.0000', 'credit' => $net->decimal, 'currency' => $currency],
                    ['account_code' => $recipientAccountCode, 'debit' => $net->decimal, 'credit' => '0.0000', 'currency' => $currency],
                ],
                [$senderAccountCode],
                'Payment',
                $transaction->reference,
            );

            $feeMetadata = $this->postFeeEntrySafely($senderAccountCode, $fee, $currency, $transaction->reference);

            $this->mark($transaction, TransactionState::SUCCESSFUL, array_merge([
                'settlement_entry_id' => $entry->id,
                'completed_at'        => now()->toIso8601String(),
            ], $feeMetadata));
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
        MoneyAmount $amount,
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

        $feeMinor = (int) ($metadata['fee_minor'] ?? 0);
        $feeDecimal = (string) ($metadata['fee_decimal'] ?? '0');
        $net = MoneyAmount::fromMinorUnits(
            (int) $transaction->amount - $feeMinor,
            $currency,
        );

        $entry = $this->ledger->postGuarded(
            "Transfer settlement {$transaction->reference}",
            [
                ['account_code' => $holdAccountCode, 'debit' => '0.0000', 'credit' => $net->decimal, 'currency' => $currency],
                ['account_code' => $recipientAccountCode, 'debit' => $net->decimal, 'credit' => '0.0000', 'currency' => $currency],
            ],
            [$holdAccountCode],
            'Payment',
            $transaction->reference,
        );

        // The fee is collected from the hold (which still holds the gross
        // reservation) in its own entry; the hold ends at zero either way.
        $feeMetadata = $this->postFeeEntrySafely($holdAccountCode, [
            'fee_minor'   => $feeMinor,
            'fee_decimal' => $feeDecimal,
            'net_minor'   => (int) $transaction->amount - $feeMinor,
            'gross_minor' => (int) $transaction->amount,
        ], $currency, $transaction->reference);

        $this->mark($transaction, TransactionState::SUCCESSFUL, array_merge([
            'settlement_entry_id' => $entry->id,
            'completed_at'        => now()->toIso8601String(),
        ], $feeMetadata));
        $this->receipts->issue($transaction->refresh(), $entry->id);

        return $transaction->refresh();
    }

    /**
     * Post the transfer fee as its own balanced entry, idempotent on the
     * transfer reference. The fee is collected from $fromAccountCode
     * (sender, or the hold on the provider path) into the
     * `PAYMENT:FEES:{currency}` control account, an ASSET holding collected
     * fees.
     *
     * Why an asset control account and not a fee-income (revenue) credit:
     * this service's principal entries are asset-style (the sender is
     * credited, i.e. its asset decreases), so the matching fee debit must
     * be a platform asset — crediting fee income here would need a second
     * debit of the same value and create money from nothing. Finance
     * recognises the control-account balance as fee income in the month-end
     * sweep; the value itself is conserved and auditable from this entry.
     *
     * @param array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int} $fee
     */
    private function postFeeEntry(
        string $fromAccountCode,
        array $fee,
        string $currency,
        string $reference,
    ): string {
        $feeDecimal = (string) $fee['fee_decimal'];
        $feeAccountCode = (string) config(
            'payment_rails.transfers.fee_account_code',
            'PAYMENT:FEES:' . $currency,
        );

        LedgerAccount::firstOrCreate(
            ['code' => $feeAccountCode],
            [
                'name'      => "Transfer fees collected {$currency}",
                'type'      => AccountType::ASSET,
                'currency'  => $currency,
                'is_active' => true,
            ],
        );

        $entry = $this->ledger->postIdempotent(
            'transfer-fee:' . $reference,
            "Transfer fee {$reference}",
            [
                ['account_code' => $feeAccountCode, 'debit' => $feeDecimal, 'credit' => '0.0000', 'currency' => $currency, 'narrative' => "Transfer fee {$reference}"],
                ['account_code' => $fromAccountCode, 'debit' => '0.0000', 'credit' => $feeDecimal, 'currency' => $currency, 'narrative' => "Transfer fee {$reference}"],
            ],
            'Payment',
            $reference,
        );

        return $entry->id;
    }

    /**
     * Fee posting that never fails the transfer: the principal entry is
     * already committed when the fee is posted, so a fee failure is
     * flagged in the transaction metadata (`fee_entry_status: pending`)
     * for operator retry — the fee entry is idempotent, so retrying it is
     * safe — instead of failing a transfer whose money already moved.
     *
     * @param array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int} $fee
     * @return array<string, mixed>
     */
    private function postFeeEntrySafely(
        string $fromAccountCode,
        array $fee,
        string $currency,
        string $reference,
    ): array {
        if ((int) $fee['fee_minor'] <= 0) {
            return [];
        }

        try {
            return [
                'fee_entry_id'     => $this->postFeeEntry($fromAccountCode, $fee, $currency, $reference),
                'fee_entry_status' => 'posted',
            ];
        } catch (Throwable $e) {
            Log::error('Transfer fee entry failed — flagged pending, principal already posted', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return [
                'fee_entry_status' => 'pending',
                'fee_error'        => mb_substr($e->getMessage(), 0, 500),
            ];
        }
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
