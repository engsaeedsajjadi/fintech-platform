<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A conversion quote: pricing only, never execution. The rate is an exact
 * decimal string from IExchangeRateProvider (never invented); the quote
 * expires at expires_at and an expired quote cannot execute.
 */
class ConversionQuote extends Model
{
    use UsesTenantConnection;

    protected $table = 'conversion_quotes';

    protected $guarded = [];

    protected $casts = [
        'amount'           => 'decimal:18',
        'rate'             => 'string',
        'fee'              => 'decimal:18',
        'converted_amount' => 'decimal:18',
        'expires_at'       => 'datetime',
        'executed_at'      => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
