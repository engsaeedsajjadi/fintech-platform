<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * OTC quote: a firm price for a direct trade, valid until `expires_at`.
 *
 * Quotes belong to the OTC model only. They never touch the order book,
 * the matching workflow, or the MatchOrderActivity.
 */
class OtcQuote extends Model
{
    protected $table = 'otc_quotes';

    protected $fillable = [
        'quote_id',
        'account_id',
        'side',
        'base_currency',
        'quote_currency',
        'amount',
        'price',
        'fee',
        'total',
        'status',
        'idempotency_key',
        'expires_at',
        'accepted_at',
        'settled_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'settled_at'  => 'datetime',
        'metadata'    => 'array',
    ];

    public function isQuoted(): bool
    {
        return $this->status === 'quoted';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || now()->greaterThan($this->expires_at);
    }

    /**
     * @param Builder<OtcQuote> $query
     * @return Builder<OtcQuote>
     */
    public function scopeQuoted($query): Builder
    {
        return $query->where('status', 'quoted');
    }
}
