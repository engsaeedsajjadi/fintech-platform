<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin/config-managed trading pair.
 *
 * A pair is tradeable on the order book only when it is active and not
 * halted. Per-pair fee overrides fall back to config('trading.fees')
 * when null. Market orders are only supported where the pair explicitly
 * enables them (capability flag).
 */
class TradingPair extends Model
{
    protected $table = 'trading_pairs';

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'is_active',
        'halted',
        'market_orders_enabled',
        'min_order_size',
        'max_order_size',
        'tick_size',
        'maker_fee_rate',
        'taker_fee_rate',
        'metadata',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'halted'                => 'boolean',
        'market_orders_enabled' => 'boolean',
        'metadata'              => 'array',
    ];

    public function getSymbolAttribute(): string
    {
        return "{$this->base_currency}/{$this->quote_currency}";
    }

    public function isTradeable(): bool
    {
        return $this->is_active && ! $this->halted;
    }

    /**
     * @param Builder<TradingPair> $query
     * @return Builder<TradingPair>
     */
    public function scopeTradeable($query): Builder
    {
        return $query->where('is_active', true)->where('halted', false);
    }

    /**
     * @param Builder<TradingPair> $query
     * @return Builder<TradingPair>
     */
    public function scopeForPair($query, string $baseCurrency, string $quoteCurrency): Builder
    {
        return $query->where('base_currency', $baseCurrency)
            ->where('quote_currency', $quoteCurrency);
    }
}
