<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Models;

use App\Domain\SafeDeal\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeDealMilestone extends Model
{
    protected $table = 'safe_deal_milestones';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'status'       => MilestoneStatus::class,
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<SafeDealTrade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(SafeDealTrade::class, 'trade_id');
    }
}
