<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text chat hook for a meeting. Messages are stored, linked to the
 * meeting and its participants — this is not a realtime chat stack.
 *
 * @property string $id
 * @property string $meeting_id
 * @property string $user_uuid
 * @property string $body
 */
class MeetingMessage extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'meeting_id',
        'user_uuid',
        'body',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
