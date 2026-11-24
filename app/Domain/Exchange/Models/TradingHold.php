<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Funds reservation for trading.
 *
 * A hold is a separate reservation row — it never writes to
 * `account_balances.balance`. Available balance is derived:
 * `balance - SUM(active holds)` for the account/asset. Holds are created
 * before matching (so a match can never overdraw), reduced as fills
 * consume them, and released on cancel / expiry.
 */
class TradingHold extends Model
{
    protected $table = 'trading_holds';

    protected $fillable = [
        'hold_id',
        'account_id',
        'account_uuid',
        'asset_code',
        'amount',
        'source_type',
        'source_id',
        'status',
        'released_at',
        'consumed_at',
    ];

    protected $casts = [
        'released_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param Builder<TradingHold> $query
     * @return Builder<TradingHold>
     */
    public function scopeActive($query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param Builder<TradingHold> $query
     * @return Builder<TradingHold>
     */
    public function scopeForAccountAsset($query, string $accountId, string $assetCode): Builder
    {
        return $query->where('account_id', $accountId)->where('asset_code', $assetCode);
    }
}
