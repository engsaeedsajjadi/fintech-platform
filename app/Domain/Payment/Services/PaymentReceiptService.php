<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\TransferReceipt;
use Illuminate\Support\Str;

/**
 * Issues an immutable receipt for a completed transfer. A receipt is a
 * read-only record of what happened (amounts, fee, state, journal entry);
 * it never changes a balance and is never edited after issue.
 */
final class PaymentReceiptService
{
    public function issue(PaymentTransaction $transaction, ?string $journalEntryId = null): TransferReceipt
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $feeMinor = (int) ($metadata['fee_minor'] ?? 0);
        $currency = strtoupper((string) $transaction->currency);

        return TransferReceipt::create([
            'receipt_number'         => 'RCPT-' . Str::ulid()->toString(),
            'payment_transaction_id' => $transaction->id,
            'reference'              => (string) $transaction->reference,
            'idempotency_key'        => $transaction->idempotency_key,
            'sender_account_code'    => (string) ($metadata['sender_account_code'] ?? ''),
            'recipient_account_code' => (string) ($metadata['recipient_account_code'] ?? ''),
            'amount_minor'           => (int) $transaction->amount,
            'amount_decimal'         => MoneyAmount::fromMinorUnits((int) $transaction->amount, $currency)->decimal,
            'fee_minor'              => $feeMinor,
            'fee_decimal'            => MoneyAmount::fromMinorUnits($feeMinor, $currency)->decimal,
            'currency'               => $currency,
            'state'                  => TransactionState::fromLegacy((string) $transaction->status)->value,
            'journal_entry_id'       => $journalEntryId,
            'metadata'               => $metadata,
        ]);
    }
}
