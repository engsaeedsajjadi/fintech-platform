<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Models;

use App\Domain\Meeting\Enums\ParticipantRole;
use App\Domain\Meeting\Enums\ParticipantStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $meeting_id
 * @property string $user_uuid
 * @property ParticipantRole $role
 * @property ParticipantStatus $status
 * @property \Illuminate\Support\Carbon|null $joined_at
 */
class MeetingParticipant extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'meeting_id',
        'user_uuid',
        'role',
        'status',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'status' => ParticipantStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
