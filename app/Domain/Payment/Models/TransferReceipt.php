<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Receipt for a completed transfer, issued by PaymentReceiptService.
 * Amounts are stored as integer minor units (source of truth) plus the
 * exact decimal major-unit string for display. A receipt never changes
 * after issue; a corrected transfer gets a new receipt.
 *
 * @property string $id
 * @property string $receipt_number
 * @property int|null $payment_transaction_id
 * @property string $reference
 * @property string|null $idempotency_key
 * @property string $sender_account_code
 * @property string $recipient_account_code
 * @property int $amount_minor
 * @property string $amount_decimal
 * @property int $fee_minor
 * @property string $fee_decimal
 * @property string $currency
 * @property string $state
 * @property string|null $journal_entry_id
 * @property array<string, mixed>|null $metadata
 *
 * @method static TransferReceipt create(array<string, mixed> $attributes = [])
 */
class TransferReceipt extends Model
{
    use HasUuids;

    protected $table = 'payment_transfer_receipts';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'metadata' => 'array',
    ];
}
