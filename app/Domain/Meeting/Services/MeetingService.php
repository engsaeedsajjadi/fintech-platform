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
use App\Domain\Meeting\Models\MeetingParticipant;
use App\Domain\Meeting\Notifications\MeetingCancelledNotification;
use App\Domain\Meeting\Notifications\MeetingRescheduledNotification;
use App\Domain\Meeting\Notifications\MeetingScheduledNotification;
use App\Domain\Meeting\Providers\MeetingProviderFactory;
use App\Domain\Meeting\Support\MeetingReservationLock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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
     * @param  array{title: string, description?: string|null, type?: string, starts_at: Carbon, ends_at: Carbon, participant_uuids?: list<string>, provider?: string|null, timezone?: string|null}  $data
     *
     * Times are stored in UTC. When `timezone` is given, naive datetimes
     * are interpreted in that zone first; datetimes that already carry an
     * offset (e.g. ISO-8601 from a client) keep their own offset.
     */
    public function schedule(User $host, array $data): Meeting
    {
        $type = MeetingType::tryFrom((string) ($data['type'] ?? MeetingType::SUPPORT->value))
            ?? throw new InvalidArgumentException('Unknown meeting type.');

        $startsAt = $this->normalizeTime($data['starts_at'], $data['timezone'] ?? null);
        $endsAt = $this->normalizeTime($data['ends_at'], $data['timezone'] ?? null);

        $this->validateWindow($startsAt, $endsAt);

        // Video-KYC eligibility: existing KYC status only.
        if ($type === MeetingType::VIDEO_KYC
            && (bool) config('meeting.require_kyc_for_video_kyc', true)
            && $host->kyc_status !== 'approved') {
            throw new MeetingAccessDeniedException(
                'Video-KYC meetings require an approved KYC status (current: ' . ($host->kyc_status ?? 'unknown') . ').'
            );
        }

        $participantUuids = array_values(array_unique(array_filter(
            $data['participant_uuids'] ?? [],
            fn (string $uuid): bool => $uuid !== $host->uuid
        )));

        $this->assertParticipantsExist($participantUuids);

        $involvedUuids = array_merge([$host->uuid], $participantUuids);

        // Serialise reservations for the same people: without this lock
        // two concurrent requests can both pass hasConflict() before
        // either inserts (check-then-insert race; no row exists to lock).
        $lock = MeetingReservationLock::acquire(
            $involvedUuids,
            (float) config('meeting.lock_timeout_seconds', 10)
        );
        if (! $lock->acquired()) {
            throw new MeetingConflictException('Another reservation for the host or a participant is in progress; please retry.');
        }

        try {
            return DB::transaction(function () use ($host, $data, $type, $startsAt, $endsAt, $participantUuids, $involvedUuids) {
                // Second layer on MySQL: lock the involved user rows and
                // any overlapping meeting rows for the duration of the
                // check-and-insert.
                User::whereIn('uuid', $involvedUuids)->lockForUpdate()->get();
                Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->lockForUpdate()->get();

                if ($this->hasConflict($involvedUuids, $startsAt, $endsAt)) {
                    throw new MeetingConflictException('The host or a participant already has a meeting at that time.');
                }

                $meeting = new Meeting([
                    'id'          => (string) Str::uuid(),
                    'host_uuid'   => $host->uuid,
                    'title'       => $data['title'],
                    'description' => $data['description'] ?? null,
                    'type'        => $type,
                    'status'      => MeetingStatus::SCHEDULED,
                    'starts_at'   => $startsAt,
                    'ends_at'     => $endsAt,
                    'provider'    => $data['provider'] ?? (string) config('meeting.default_provider', 'sandbox'),
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
                        'role'      => ParticipantRole::PARTICIPANT,
                        'status'    => ParticipantStatus::INVITED,
                    ]);
                }

                $host->notify(new MeetingScheduledNotification($meeting));
                foreach ($meeting->participants as $participant) {
                    $participant->user?->notify(new MeetingScheduledNotification($meeting));
                }

                return $meeting;
            });
        } finally {
            $lock->release();
        }
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
            'status'        => MeetingStatus::CANCELLED,
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
        ]);

        // Best-effort room teardown; a provider failure must never block
        // the cancellation itself (Jitsi/sandbox delete is a no-op).
        try {
            $this->providerFactory->resolve($meeting->provider)->deleteRoom($meeting);
        } catch (Throwable) {
            report(new RuntimeException('Meeting room teardown failed for meeting ' . $meeting->id));
        }

        if ($wasAdminAction) {
            AuditLog::log(
                'meeting.cancelled_by_admin',
                $meeting,
                ['status'     => $meeting->getOriginal('status')],
                ['status'     => MeetingStatus::CANCELLED->value, 'reason' => $reason],
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

    public function reschedule(Meeting $meeting, User $actor, Carbon $startsAt, Carbon $endsAt, ?string $timezone = null): Meeting
    {
        $this->assertCanManage($meeting, $actor);

        if (! $meeting->status->isActive()) {
            throw new InvalidArgumentException('Only an active meeting can be rescheduled.');
        }

        $startsAt = $this->normalizeTime($startsAt, $timezone);
        $endsAt = $this->normalizeTime($endsAt, $timezone);
        $this->validateWindow($startsAt, $endsAt);

        /** @var list<string> $involvedUuids */
        $involvedUuids = array_merge(
            [$meeting->host_uuid],
            $meeting->participants()->pluck('user_uuid')->all()
        );

        // Same serialisation as schedule(): the previous implementation
        // checked conflicts outside any transaction, so a concurrent
        // booking could land in the target slot mid-reschedule.
        $lock = MeetingReservationLock::acquire(
            $involvedUuids,
            (float) config('meeting.lock_timeout_seconds', 10)
        );
        if (! $lock->acquired()) {
            throw new MeetingConflictException('Another reservation for the host or a participant is in progress; please retry.');
        }

        try {
            return DB::transaction(function () use ($meeting, $actor, $startsAt, $endsAt, $involvedUuids) {
                Meeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
                User::whereIn('uuid', $involvedUuids)->lockForUpdate()->get();

                if ($this->hasConflict($involvedUuids, $startsAt, $endsAt, $meeting->id)) {
                    throw new MeetingConflictException('The host or a participant already has a meeting at that time.');
                }

                $wasAdminAction = $meeting->host_uuid !== $actor->uuid;
                $oldStart = $meeting->starts_at->toIso8601String();

                $meeting->update([
                    'starts_at'        => $startsAt,
                    'ends_at'          => $endsAt,
                    'status'           => MeetingStatus::SCHEDULED,
                    'reminder_sent_at' => null,
                ]);

                if ($wasAdminAction) {
                    AuditLog::log(
                        'meeting.rescheduled_by_admin',
                        $meeting,
                        ['starts_at'  => $oldStart],
                        ['starts_at'  => $startsAt->toIso8601String()],
                        ['admin_uuid' => $actor->uuid, 'host_uuid' => $meeting->host_uuid],
                        'meeting,admin'
                    );
                }

                $meeting->host?->notify(new MeetingRescheduledNotification($meeting));
                foreach ($meeting->participants as $participant) {
                    $participant->user?->notify(new MeetingRescheduledNotification($meeting));
                }

                return $meeting->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Accept or decline an invitation. Only an invited participant may
     * respond, and only while the meeting is still active.
     */
    public function respondToInvitation(Meeting $meeting, User $user, ParticipantStatus $status): MeetingParticipant
    {
        if (! in_array($status, [ParticipantStatus::ACCEPTED, ParticipantStatus::DECLINED], true)) {
            throw new InvalidArgumentException('Invitation response must be accepted or declined.');
        }

        if (! $meeting->status->isActive()) {
            throw new InvalidArgumentException('Only an active meeting can be responded to.');
        }

        $participant = $meeting->participants()->where('user_uuid', $user->uuid)->first()
            ?? throw new MeetingAccessDeniedException('Only an invited participant can respond to this meeting.');

        $participant->update(['status' => $status]);

        return $participant->refresh();
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
     * Free slots for a host on a given day, within working hours.
     *
     * The day and working hours are interpreted in `$timezone` (e.g. the
     * host's own zone, Asia/Tehran); without it the application timezone
     * is used. Busy time includes meetings where the host is a participant,
     * not only meetings they host — the previous version only looked at
     * hosted meetings and compared dates in the DB/server timezone, so a
     * Tehran host could be double-booked through a 10:00 UTC slot.
     *
     * @return list<array{starts_at: Carbon, ends_at: Carbon, timezone: string}>
     */
    public function availability(User $host, Carbon $date, ?string $timezone = null): array
    {
        $tz = $timezone ?? (string) config('app.timezone', 'UTC');
        $day = $date->copy()->setTimezone($tz)->startOfDay();

        $slotMinutes = (int) config('meeting.slot_minutes', 30);
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) config('meeting.working_hours.start', '09:00')));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) config('meeting.working_hours.end', '17:00')));

        $cursor = $day->copy()->setTime($startHour, $startMinute);
        $dayEnd = $day->copy()->setTime($endHour, $endMinute);

        // Busy intervals in the requested zone: hosted meetings plus
        // meetings where the host participates, overlapping the day window.
        $busy = Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
            ->where('starts_at', '<', $dayEnd->copy()->setTimezone('UTC'))
            ->where('ends_at', '>', $cursor->copy()->setTimezone('UTC'))
            ->where(function ($query) use ($host) {
                $query->where('host_uuid', $host->uuid)
                    ->orWhereHas('participants', fn ($q) => $q->where('user_uuid', $host->uuid));
            })
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Meeting $m) => [
                'starts_at' => $m->starts_at->copy()->setTimezone($tz),
                'ends_at'   => $m->ends_at->copy()->setTimezone($tz),
            ]);

        $slots = [];
        while ($cursor->copy()->addMinutes($slotMinutes) <= $dayEnd) {
            $slotEnd = $cursor->copy()->addMinutes($slotMinutes);
            $clashes = $busy->contains(fn (array $m): bool => $m['starts_at'] < $slotEnd && $m['ends_at'] > $cursor);

            if (! $clashes && $cursor->isFuture()) {
                $slots[] = ['starts_at' => $cursor->copy(), 'ends_at' => $slotEnd, 'timezone' => $tz];
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
            'body'      => $body,
        ]);
    }

    /** @return Collection<int, MeetingMessage> */
    public function listMessages(Meeting $meeting, User $user): Collection
    {
        if (! $this->canJoin($meeting, $user)) {
            throw new MeetingAccessDeniedException('Only participants can read this meeting chat.');
        }

        return $meeting->messages()->with('user')->orderBy('created_at')->orderBy('id')->get();
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
            'total'       => Meeting::count(),
            'by_status'   => $byStatus,
            'upcoming_7d' => Meeting::whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
                ->whereBetween('starts_at', [now(), now()->addDays(7)])
                ->count(),
            'today'             => Meeting::whereDate('starts_at', now()->toDateString())->count(),
            'video_kyc_pending' => Meeting::where('type', MeetingType::VIDEO_KYC->value)
                ->whereIn('status', [MeetingStatus::SCHEDULED->value, MeetingStatus::CONFIRMED->value])
                ->count(),
            'participants_total' => DB::table('meeting_participants')->count(),
            'recent'             => Meeting::with('host')->orderByDesc('created_at')->limit(10)->get(),
        ];
    }

    private function assertCanManage(Meeting $meeting, User $actor): void
    {
        if ($meeting->host_uuid !== $actor->uuid && ! $actor->hasRole('admin')) {
            throw new MeetingAccessDeniedException('Only the host or staff can manage this meeting.');
        }
    }

    /**
     * Interpret a meeting time in the caller's timezone and store UTC.
     * A datetime that already carries an explicit offset keeps it; a
     * naive datetime is read in `$timezone` (or the app timezone).
     */
    private function normalizeTime(Carbon $time, ?string $timezone): Carbon
    {
        if ($timezone === null || $timezone === '') {
            return $time->copy()->setTimezone('UTC');
        }

        // Carbon::parse() already applied the offset for ISO-8601 input,
        // so only re-interpret when the value is naive — i.e. when it is
        // still in the application timezone. Re-interpreting means keeping
        // the wall-clock fields and reading them in the given zone
        // (setTimezone() would instead convert the instant and keep the
        // same moment, which is the bug this branch must avoid).
        if ($time->getTimezone()->getName() === config('app.timezone', 'UTC')) {
            $rebuilt = Carbon::createFromFormat('Y-m-d H:i:s', $time->format('Y-m-d H:i:s'), $timezone);
            if ($rebuilt === null) {
                throw new InvalidArgumentException('Invalid meeting time.');
            }
            $time = $rebuilt;
        }

        return $time->copy()->setTimezone('UTC');
    }

    /** Shared window rules for schedule() and reschedule(). */
    private function validateWindow(Carbon $startsAt, Carbon $endsAt): void
    {
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
    }

    /** @param  list<string>  $uuids */
    private function assertParticipantsExist(array $uuids): void
    {
        if ($uuids === []) {
            return;
        }

        $existing = User::whereIn('uuid', $uuids)->pluck('uuid')->all();
        $missing = array_values(array_diff($uuids, $existing));

        if ($missing !== []) {
            throw new InvalidArgumentException('Unknown participant: ' . implode(', ', $missing));
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
