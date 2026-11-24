<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Models;

use App\Domain\Meeting\Enums\MeetingStatus;
use App\Domain\Meeting\Enums\MeetingType;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Video meeting (appointment). This is the video-meeting domain — it is
 * unrelated to the microfinance group-meeting record-keeping in
 * App\Domain\Microfinance\Models\GroupMeeting.
 *
 * @property string $id
 * @property string $host_uuid
 * @property string $title
 * @property string|null $description
 * @property MeetingType $type
 * @property MeetingStatus $status
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property string $provider
 * @property string|null $room_name
 * @property string|null $join_url
 * @property \Illuminate\Support\Carbon|null $reminder_sent_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
class Meeting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'id',
        'host_uuid',
        'title',
        'description',
        'type',
        'status',
        'starts_at',
        'ends_at',
        'provider',
        'room_name',
        'join_url',
        'reminder_sent_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => MeetingType::class,
            'status' => MeetingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_uuid', 'uuid');
    }

    /** @return HasMany<MeetingParticipant> */
    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class, 'meeting_id');
    }

    /** @return HasMany<MeetingMessage> */
    public function messages(): HasMany
    {
        return $this->hasMany(MeetingMessage::class, 'meeting_id');
    }

    public function isParticipant(User $user): bool
    {
        if ($this->host_uuid === $user->uuid) {
            return true;
        }

        return $this->participants()->where('user_uuid', $user->uuid)->exists();
    }

    public function overlaps(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): bool
    {
        return $this->starts_at < $endsAt && $this->ends_at > $startsAt;
    }
}
