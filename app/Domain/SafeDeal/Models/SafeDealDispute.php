<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Models;

use App\Domain\SafeDeal\Enums\DisputeResolution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeDealDispute extends Model
{
    protected $table = 'safe_deal_disputes';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'resolution'   => DisputeResolution::class,
        'evidence'     => 'array',
        'seller_share' => 'array',
        'resolved_at'  => 'datetime',
    ];

    /** @return BelongsTo<SafeDealTrade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(SafeDealTrade::class, 'trade_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
