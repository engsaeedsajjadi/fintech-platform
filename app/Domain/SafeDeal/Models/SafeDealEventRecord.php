<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event log row for one SafeDeal domain event.
 *
 * Rows are never updated or deleted; they are the durable history the
 * final audit record is built from.
 */
class SafeDealEventRecord extends Model
{
    protected $table = 'safe_deal_events';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'payload' => 'array',
    ];

    public $timestamps = false;

    /** @return BelongsTo<SafeDealTrade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(SafeDealTrade::class, 'trade_id');
    }
}
