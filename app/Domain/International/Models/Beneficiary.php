<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A beneficiary (destination account) of a counterparty.
 *
 * Deliberately stores NO full account numbers and NO full IBANs — only
 * the last 4 characters as a recognition hint, plus the bank name and
 * BIC (public routing data). The full destination account details live
 * with the (not yet connected) provider; this codebase holds no bank
 * account numbers, following the IranianRailTransfer precedent.
 *
 * @property string $id
 * @property int $user_id
 * @property string $counterparty_id
 * @property string $display_name
 * @property string $country
 * @property string|null $bank_name
 * @property string|null $bic
 * @property string|null $iban_last4
 * @property string|null $account_last4
 * @property string $currency
 * @property bool $is_active
 */
class Beneficiary extends Model
{
    use HasUuids;

    protected $table = 'beneficiaries';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }
}
