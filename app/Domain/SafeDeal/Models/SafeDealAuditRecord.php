<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Final trade audit record: written exactly once, when a trade reaches a
 * terminal state (settled / cancelled / refunded). It snapshots the trade,
 * the full event history hash, the ledger entry ids and the dispute
 * outcome, so the whole trade can be verified after the fact without
 * replaying anything.
 */
class SafeDealAuditRecord extends Model
{
    protected $table = 'safe_deal_audit_records';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'event_history'    => 'array',
        'ledger_entry_ids' => 'array',
        'snapshot'         => 'array',
    ];

    /** @return BelongsTo<SafeDealTrade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(SafeDealTrade::class, 'trade_id');
    }
}
