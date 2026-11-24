<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use App\Domain\International\Enums\InternationalRail;
use App\Domain\International\Enums\InternationalTransferStatus;
use App\Domain\International\Enums\TransferPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An international P2P transfer request.
 *
 * Money amounts are integer minor units of `currency` — never floats.
 * The fee is computed at creation and posted as a SEPARATE ledger
 * entry from the principal (see InternationalTransferService).
 * `journal_entry_id` / `fee_journal_entry_id` /
 * `settlement_journal_entry_id` link the transfer to its balanced
 * ledger postings; a transfer with money reserved always has entries.
 *
 * @property string $id
 * @property int $user_id
 * @property string $international_account_id
 * @property string $counterparty_id
 * @property string $beneficiary_id
 * @property string|null $exchange_quotation_id
 * @property string $reference
 * @property string $end_to_end_reference
 * @property string|null $external_reference
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int $amount_minor
 * @property int $fee_minor
 * @property string $currency
 * @property string $destination_currency
 * @property int $destination_amount_minor
 * @property TransferPurpose $purpose
 * @property string|null $purpose_detail
 * @property InternationalRail $rail
 * @property InternationalTransferStatus $status
 * @property string $screening_status
 * @property string|null $screening_notes
 * @property string|null $failure_reason
 * @property string|null $journal_entry_id
 * @property string|null $fee_journal_entry_id
 * @property string|null $settlement_journal_entry_id
 */
class InternationalTransfer extends Model
{
    use HasUuids;

    protected $table = 'international_transfers';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'amount_minor'             => 'integer',
        'fee_minor'                => 'integer',
        'destination_amount_minor' => 'integer',
        'purpose'                  => TransferPurpose::class,
        'rail'                     => InternationalRail::class,
        'status'                   => InternationalTransferStatus::class,
        'metadata'                 => 'array',
    ];

    /**
     * @return BelongsTo<InternationalAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(InternationalAccount::class, 'international_account_id');
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }

    /**
     * @return BelongsTo<Beneficiary, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    /**
     * @return BelongsTo<ExchangeQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ExchangeQuotation::class, 'exchange_quotation_id');
    }

    /**
     * @return HasMany<InternationalTransferStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(InternationalTransferStatusHistory::class, 'international_transfer_id');
    }

    /**
     * @return HasMany<SettlementInstruction, $this>
     */
    public function settlementInstructions(): HasMany
    {
        return $this->hasMany(SettlementInstruction::class, 'international_transfer_id');
    }

    /**
     * @return HasMany<InternationalAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(InternationalAuditLog::class, 'international_transfer_id');
    }
}
