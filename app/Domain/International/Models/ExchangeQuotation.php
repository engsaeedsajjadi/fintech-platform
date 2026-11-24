<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An exchange quotation: a locked rate for one amount and currency pair.
 *
 * The rate is operator-supplied (source `manual`) — there is no live FX
 * feed. A quotation expires after its TTL; only an `accepted`,
 * unexpired quotation whose currencies and amount match the transfer
 * may fund a cross-currency transfer.
 *
 * @property string $id
 * @property int $user_id
 * @property string $source_currency
 * @property string $destination_currency
 * @property int $amount_minor
 * @property string $rate
 * @property int $destination_amount_minor
 * @property string $source
 * @property string $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 */
class ExchangeQuotation extends Model
{
    use HasUuids;

    protected $table = 'exchange_quotations';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'amount_minor'             => 'integer',
        'destination_amount_minor' => 'integer',
        'expires_at'               => 'datetime',
        'accepted_at'              => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->status === 'expired' || now()->greaterThan($this->expires_at);
    }

    public function isUsable(): bool
    {
        return $this->status === 'accepted' && ! $this->isExpired();
    }
}
