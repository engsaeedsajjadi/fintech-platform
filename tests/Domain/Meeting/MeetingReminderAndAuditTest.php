<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Meeting\Jobs\SendMeetingReminderJob;
use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Notifications\MeetingReminderNotification;
use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function scheduleSoonMeeting(User $host, int $minutesFromNow = 30): Meeting
{
    $startsAt = now()->addMinutes($minutesFromNow);
    $endsAt = $startsAt->copy()->addMinutes(30);

    return app(MeetingService::class)->schedule($host, [
        'title' => 'Soon', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
}

it('queues a reminder job for meetings starting within the reminder window', function () {
    Queue::fake();

    scheduleSoonMeeting($this->user, 30);
    // Outside the 60-minute window: no reminder.
    scheduleSoonMeeting($this->user, 180);

    $queued = app(MeetingService::class)->queueDueReminders();

    expect($queued)->toBe(1);

    Queue::assertPushed(SendMeetingReminderJob::class, 1);
});

it('reminder job notifies the host and is idempotent', function () {
    Notification::fake();

    $meeting = scheduleSoonMeeting($this->user, 30);

    (new SendMeetingReminderJob($meeting->id))->handle();

    Notification::assertSentTo($this->user, MeetingReminderNotification::class);
    expect($meeting->refresh()->reminder_sent_at)->not->toBeNull();

    // A retried job must not send a second reminder.
    (new SendMeetingReminderJob($meeting->id))->handle();

    Notification::assertSentToTimes($this->user, MeetingReminderNotification::class, 1);
});

it('reminder job skips cancelled meetings', function () {
    Notification::fake();

    $meeting = scheduleSoonMeeting($this->user, 30);
    app(MeetingService::class)->cancel($meeting, $this->user);

    (new SendMeetingReminderJob($meeting->id))->handle();

    Notification::assertNotSentTo($this->user, MeetingReminderNotification::class);
});

it('admin cancelling another user meeting writes an audit record', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Admin cancel', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    app(MeetingService::class)->cancel($meeting, $admin, 'Policy violation');

    expect($meeting->refresh()->status->value)->toBe('cancelled');

    $audit = AuditLog::where('action', 'meeting.cancelled_by_admin')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->auditable_type)->toBe(Meeting::class)
        ->and($audit->auditable_id)->toBe($meeting->id)
        ->and($audit->new_values['status'])->toBe('cancelled');
});

it('host cancelling their own meeting does not write an admin audit record', function () {
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Own cancel', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    app(MeetingService::class)->cancel($meeting, $this->user);

    expect(AuditLog::where('action', 'meeting.cancelled_by_admin')->count())->toBe(0);
});

it('non-host non-admin cannot cancel a meeting', function () {
    $outsider = User::factory()->create();
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Protected', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    expect(fn () => app(MeetingService::class)->cancel($meeting, $outsider))
        ->toThrow(\App\Domain\Meeting\Exceptions\MeetingAccessDeniedException::class);
});
