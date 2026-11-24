<?php

declare(strict_types=1);

use App\Domain\Meeting\Exceptions\MeetingProviderNotConfiguredException;
use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Notifications\MeetingRescheduledNotification;
use App\Domain\Meeting\Providers\JitsiMeetingProvider;
use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function reschedulableMeeting(User $host): Meeting
{
    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];

    return app(MeetingService::class)->schedule($host, [
        'title' => 'Reschedule me', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
}

it('rejects rescheduling beyond the maximum duration', function () {
    $meeting = reschedulableMeeting($this->user);
    $newStart = Carbon::tomorrow()->setTime(10, 0);

    // schedule() enforced the 120-minute cap; reschedule() did not.
    expect(fn () => app(MeetingService::class)->reschedule(
        $meeting, $this->user, $newStart, $newStart->copy()->addMinutes(180)
    ))->toThrow(InvalidArgumentException::class);

    expect($meeting->refresh()->starts_at->format('H:i'))->toBe('10:00');
});

it('rejects rescheduling into the past', function () {
    $meeting = reschedulableMeeting($this->user);

    expect(fn () => app(MeetingService::class)->reschedule(
        $meeting, $this->user, now()->subHour(), now()->subMinutes(30)
    ))->toThrow(InvalidArgumentException::class);
});

it('sends a rescheduled notification, not a scheduled one, on reschedule', function () {
    Notification::fake();
    $participant = User::factory()->create();
    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Notify move', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);

    Notification::fake(); // drop the scheduling notifications
    app(MeetingService::class)->reschedule($meeting, $this->user, $startsAt->copy()->addDay(), $endsAt->copy()->addDay());

    Notification::assertSentTo($this->user, MeetingRescheduledNotification::class);
    Notification::assertSentTo($participant, MeetingRescheduledNotification::class);
});

it('jitsi rejects an invalid base URL instead of building a broken join URL', function () {
    config(['meeting.jitsi.base_url' => 'not-a-url']);

    $meeting = new Meeting(['id' => (string) Str::uuid(), 'host_uuid' => $this->user->uuid, 'title' => 'Test']);

    expect(fn () => (new JitsiMeetingProvider)->createRoom($meeting))
        ->toThrow(MeetingProviderNotConfiguredException::class, 'valid http(s) URL');
});

it('scheduling with unconfigured jitsi answers 422 with a localised message, not a 500', function () {
    config(['meeting.jitsi.base_url' => '']);
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $response = $this->postJson('/api/v1/meetings', [
        'title' => 'Jitsi test', 'type' => 'support',
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
        'provider' => 'jitsi',
    ], ['Accept-Language' => 'fa']);

    $response->assertStatus(422);
    expect($response->json('errors.provider.0'))->toBe(__('meeting.provider_not_configured', [], 'fa'));
    expect(Meeting::count())->toBe(0);
});

it('a conflicting booking answers 422 with a localised message', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);
    reschedulableMeeting($this->user);

    $startsAt = Carbon::tomorrow()->setTime(10, 30);
    $response = $this->postJson('/api/v1/meetings', [
        'title' => 'Overlap', 'type' => 'support',
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
    ], ['Accept-Language' => 'fa']);

    $response->assertStatus(422);
    expect($response->json('errors.starts_at.0'))->toBe(__('meeting.conflict', [], 'fa'));
});
