<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Services;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Meeting\Enums\MeetingStatus;
use App\Domain\Meeting\Enums\MeetingType;
use App\Domain\Meeting\Enums\ParticipantRole;
use App\Domain\Meeting\Enums\ParticipantStatus;
use App\Domain\Meeting\Exceptions\MeetingAccessDeniedException;
use App\Domain\Meeting\Exceptions\MeetingConflictException;
use App\Domain\Meeting\Jobs\SendMeetingReminderJob;
use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Models\MeetingMessage;
use App\Domain\Meeting\Notifications\MeetingCancelledNotification;
use App\Domain\Meeting\Notifications\MeetingScheduledNotification;
use App\Domain\Meeting\Providers\MeetingProviderFactory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Meeting scheduling + participation service.
 *
 * Eligibility reads the host's existing KYC status (users.kyc_status,
 * owned by the Compliance domain) for video-KYC meetings only; it never
 * calls a KYC vendor and never changes KYC state. Privileged admin
 * actions (cancelling / rescheduling someone else's meeting) write an
 * audit record through the existing Compliance AuditLog mechanism.
 */
final class MeetingService
{
    public function __construct(private readonly MeetingProviderFactory $providerFactory)
    {
    }

    /**
     * @param  array{title: string, description?: string|null, type?: string, starts_at: Carbon, ends_at: Carbon, participant_uuids?: list<string>, provider?: string|null}  $data
     */
    public function schedule(User $host, array $data): Meeting
    {
        $type = MeetingType::tryFrom((string) ($data['type'] ?? MeetingType::SUPPORT->value))
            ?? throw new InvalidArgumentException('Unknown meeting type.');

        $startsAt = $data['starts_at'];
        $endsAt = $data['ends_at'];

        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('Meeting end must be after its start.');
        }

        if ($startsAt->isPast()) {
            throw new InvalidArgumentException('Meeting start must be in the future.');
        }

        if ($startsAt->diffInMinutes($endsAt) > (int) config('meeting.max_duration_minutes', 120)) {
            throw new InvalidArgumentException('Meeting exceeds the maximum duration.');
        }

        if ($startsAt->diffInDays(now()) > (int) config('meeting.max_advance_days', 90)) {
            throw new InvalidArgumentException('Meeting is too far in the future.');
        }

        // Video-KYC eligibility: existing KYC status only.
        if ($type === MeetingType::VIDEO_KYC
            && (bool) config('meeting.require_kyc_for_video_kyc', true)
            && $host->kyc_status !== 'approved') {
            throw new MeetingAccessDeniedException(
                'Video-KYC meetings require an approved KYC status (current: '.($host->kyc_status ?? 'unknown').').'
            );
        }

        $participantUuids = array_values(array_unique(array_filter(
            $data['participant_uuids'] ?? [],
            fn (string $uuid): bool => $uuid !== $host->uuid
        )));

        $involvedUuids = array_merge([$host->uuid], $participantUuids);

        return DB::transaction(function () use ($host, $data, $type, $startsAt, $endsAt, $participantUuids, $involvedUuids) {
            if ($this->hasConflict($involvedUuids, $startsAt, $endsAt)) {
                throw new MeetingConflictException('The host or a participant already has a meeting at that time.');
            }

            $meeting = new Meeting([
                'id' => (string) Str::uuid(),
                'host_uuid' => $host->uuid,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $type,
                'status' => MeetingStatus::SCHEDULED,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'provider' => $data['provider'] ?? (string) config('meeting.default_provider', 'sandbox'),
            ]);

            // Provider room creation happens before persisting so a
            // not-configured provider fails the schedule explicitly
            // instead of leaving a room-less meeting behind.
            $room = $this->providerFactory->resolve($meeting->provider)->createRoom($meeting);
            $meeting->room_name = $room['room_name'];
            $meeting->join_url = $room['join_url'];
            $meeting->save();

            foreach ($participantUuids as $uuid) {
                $meeting->participants()->create([
                    'user_uuid' => $uuid,
                    'role' => ParticipantRole::PARTICIPANT,
                    'status' => ParticipantStatus::INVITED,
                ]);
            }

            $host->notify(new MeetingScheduledNotification($meeting));
            foreach ($meeting->participants as $participant) {
                $participant->user?->notify(new MeetingScheduledNotification($meeting));
            }

            return $meeting;
        });
    }

    public function cancel(Meeting $meeting, User $actor, ?string $reason = null): Meeting
    {
        $this->assertCanManage($meeting, $actor);

        if ($meeting->status === MeetingStatus::CANCELLED) {
            return $meeting;
        }

        if (! $meeting->status->isActive()) {
            throw new InvalidArgumentException('Only an active meeting can be cancelled.');
        }

        $wasAdminAction = $meeting->host_uuid !== $actor->uuid;

        $meeting->update([
            'status' => MeetingStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        if ($wasAdminAction) {
            AuditLog::log(
                'meeting.cancelled_by_admin',
                $meeting,
                ['status' => $meeting->getOriginal('status')],
                ['status' => MeetingStatus::CANCELLED->value, 'reason' => $reason],
                ['admin_uuid' => $actor->uuid, 'host_uuid' => $meeting->host_uuid],
                'meeting,admin'
            );
        }

        $meeting->host?->notify(new MeetingCancelledNotification($meeting));
        foreach ($meeting->participants as $participant) {
            $participant->user?->notify(new MeetingCancelledNotification($meeting));
        }

        return $meeting->refresh();
    }

    public function reschedule(Meeting $meeting, User $actor, Carbon $startsAt, Carbon $endsAt): Meeting
    {
        $this->assertCanManage($meeting, $actor);

        if (! $meeting->status->isActive()) {
            throw new InvalidArgumentException('Only an active meeting can be rescheduled.');
        }

        if ($endsAt <= $startsAt || $startsAt->isPast()) {
            throw new InvalidArgumentException('Invalid new meeting time.');
        }

        $involvedUuids = array_merge(
            [$meeting->host_uuid],
            $meeting->participants()->pluck('user_uuid')->all()
        );

        if ($this->hasConflict($involvedUuids, $startsAt, $endsAt, $meeting->id)) {
            throw new MeetingConflictException('The host or a participant already has a meeting at that time.');
        }

        $wasAdminAction = $meeting->host_uuid !== $actor->uuid;
        $oldStart = $meeting->starts_at->toIso8601String();

        $meeting->update([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => MeetingStatus::SCHEDULED,
            'reminder_sent_at' => null,
        ]);

        if ($wasAdminAction) {
            AuditLog::log(
                'meeting.rescheduled_by_admin',
                $meeting,
                ['starts_at' => $oldStart],
                ['starts_at' => $startsAt->toIso8601String()],
                ['admin_uuid' => $actor->uuid, 'host_uuid' => $meeting->host_uuid],
                'meeting,admin'
            );
        }

        $meeting->host?->notify(new MeetingScheduledNotification($meeting));
        foreach ($meeting->participants as $participant) {
            $participant->user?->notify(new MeetingScheduledNotification($meeting));
        }

        return $meeting->refresh();
    }

    /** Returns the join URL for an authorised user. */
    public function join(Meeting $meeting, User $user): string
    {
        if (! $meeting->status->isActive()) {
            throw new MeetingAccessDeniedException('This meeting is not active.');
        }

        if (! $this->canJoin($meeting, $user)) {
            throw new MeetingAccessDeniedException('Only invited participants, the host, or staff can join this meeting.');
        }

        $meeting->participants()
            ->where('user_uuid', $user->uuid)
            ->update(['joined_at' => now()]);

        if ($meeting->join_url === null) {
            $room = $this->providerFactory->resolve($meeting->provider)->createRoom($meeting);
            $meeting->update(['room_name' => $room['room_name'], 'join_url' => $room['join_url']]);
        }

        return (string) $meeting->join_url;
    }

    public function canJoin(Meeting $meeting, User $user): bool
    {
        return $meeting->host_uuid === $user->uuid
            || $meeting->participants()->where('user_uuid', $user->uuid)->exists()
            || $user->hasRole('admin');
    }

    /**
     * Free 30-minute slots for a host on a given day, within working hours.
     *
     * @return list<array{starts_at: Carbon, ends_at: Carbon}>
     */
    public function availability(User $host, Carbon $date): array
    {
        $slotMinutes = (int) config('meeting.slot_minutes', 30);
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) config('meeting.working_hours.start', '09:00')));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) config('meeting.working_hours.end', '17:00')));

        $cursor = $date->copy()->setTime($startHour, $startMinute);
        $dayEnd = $date->copy()->setTime($endHour, $endMinute);

        $busy = Meeting::where('host_uuid', $host->uuid)
            ->whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
            ->whereDate('starts_at', $date->toDateString())
            ->get(['starts_at', 'ends_at']);

        $slots = [];
        while ($cursor->copy()->addMinutes($slotMinutes) <= $dayEnd) {
            $slotEnd = $cursor->copy()->addMinutes($slotMinutes);
            $clashes = $busy->contains(fn (Meeting $m): bool => $m->overlaps($cursor, $slotEnd));

            if (! $clashes && $cursor->isFuture()) {
                $slots[] = ['starts_at' => $cursor->copy(), 'ends_at' => $slotEnd];
            }

            $cursor = $slotEnd;
        }

        return $slots;
    }

    /** Queue reminders for meetings starting within the reminder window. */
    public function queueDueReminders(): int
    {
        $windowEnd = now()->addMinutes((int) config('meeting.reminder_minutes_before', 60));

        $due = Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '<=', $windowEnd)
            ->where('starts_at', '>', now())
            ->get();

        foreach ($due as $meeting) {
            SendMeetingReminderJob::dispatch($meeting->id);
        }

        return $due->count();
    }

    /** Text chat hook: store a message linked to the meeting. */
    public function addMessage(Meeting $meeting, User $user, string $body): MeetingMessage
    {
        if (! $this->canJoin($meeting, $user)) {
            throw new MeetingAccessDeniedException('Only participants can message in this meeting.');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 2000) {
            throw new InvalidArgumentException('Message must be between 1 and 2000 characters.');
        }

        return $meeting->messages()->create([
            'user_uuid' => $user->uuid,
            'body' => $body,
        ]);
    }

    /** @return Collection<int, MeetingMessage> */
    public function listMessages(Meeting $meeting, User $user): Collection
    {
        if (! $this->canJoin($meeting, $user)) {
            throw new MeetingAccessDeniedException('Only participants can read this meeting chat.');
        }

        return $meeting->messages()->with('user')->orderBy('created_at')->get();
    }

    /** @return Collection<int, Meeting> */
    public function historyFor(User $user): Collection
    {
        return Meeting::where(function ($query) use ($user) {
            $query->where('host_uuid', $user->uuid)
                ->orWhereHas('participants', fn ($q) => $q->where('user_uuid', $user->uuid));
        })
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->get();
    }

    /** Data for the admin scheduling dashboard. */
    public function adminDashboardStats(): array
    {
        $byStatus = Meeting::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'total' => Meeting::count(),
            'by_status' => $byStatus,
            'upcoming_7d' => Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
                ->whereBetween('starts_at', [now(), now()->addDays(7)])
                ->count(),
            'today' => Meeting::whereDate('starts_at', now()->toDateString())->count(),
            'video_kyc_pending' => Meeting::where('type', MeetingType::VIDEO_KYC->value)
                ->whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
                ->count(),
            'participants_total' => DB::table('meeting_participants')->count(),
            'recent' => Meeting::with('host')->orderByDesc('created_at')->limit(10)->get(),
        ];
    }

    private function assertCanManage(Meeting $meeting, User $actor): void
    {
        if ($meeting->host_uuid !== $actor->uuid && ! $actor->hasRole('admin')) {
            throw new MeetingAccessDeniedException('Only the host or staff can manage this meeting.');
        }
    }

    /** @param  list<string>  $uuids */
    private function hasConflict(array $uuids, Carbon $startsAt, Carbon $endsAt, ?string $excludeId = null): bool
    {
        $query = Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($uuids) {
                $query->whereIn('host_uuid', $uuids)
                    ->orWhereHas('participants', fn ($q) => $q->whereIn('user_uuid', $uuids));
            });

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
