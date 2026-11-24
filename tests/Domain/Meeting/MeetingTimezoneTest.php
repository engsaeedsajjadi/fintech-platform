<?php

declare(strict_types=1);

use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;

it('stores meeting times in UTC when a timezone is given', function () {
    // 10:00 in Asia/Tehran (UTC+3:30) is 06:30 UTC.
    $startsAt = Carbon::create(2026, 11, 26, 10, 0, 0, 'Asia/Tehran');
    $endsAt = $startsAt->copy()->addHour();

    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Tehran time', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'timezone' => 'Asia/Tehran',
    ]);

    expect($meeting->starts_at->setTimezone('UTC')->format('H:i'))->toBe('06:30')
        ->and($meeting->starts_at->setTimezone('Asia/Tehran')->format('H:i'))->toBe('10:00');
});

it('interprets naive datetimes in the given timezone', function () {
    $startsAt = Carbon::parse('2026-11-26 10:00:00'); // naive, app tz (UTC)
    $endsAt = Carbon::parse('2026-11-26 11:00:00');

    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Naive Tehran', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'timezone' => 'Asia/Tehran',
    ]);

    expect($meeting->starts_at->setTimezone('UTC')->format('H:i'))->toBe('06:30');
});

it('availability in the host timezone blocks the right slots', function () {
    // Host busy 10:00–11:00 Tehran time (= 06:30–07:30 UTC).
    $startsAt = Carbon::create(2026, 11, 26, 10, 0, 0, 'Asia/Tehran');
    app(MeetingService::class)->schedule($this->user, [
        'title' => 'Busy Tehran', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
        'timezone' => 'Asia/Tehran',
    ]);

    $day = Carbon::create(2026, 11, 26, 0, 0, 0, 'Asia/Tehran');
    $slots = app(MeetingService::class)->availability($this->user, $day, 'Asia/Tehran');
    $starts = array_map(fn (array $s) => $s['starts_at']->format('H:i'), $slots);

    // Before the fix the busy check ran in UTC, so the 10:00 Tehran slot
    // looked free and the 06:30 UTC slot was wrongly blocked.
    expect($starts)->not->toContain('10:00')
        ->and($starts)->not->toContain('10:30')
        ->and($starts)->toContain('09:00')
        ->and($starts)->toContain('11:00')
        ->and($slots[0]['timezone'])->toBe('Asia/Tehran');
});

it('availability treats meetings where the host participates as busy', function () {
    $otherHost = User::factory()->create();
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    app(MeetingService::class)->schedule($otherHost, [
        'title' => 'Host is guest here', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
        'participant_uuids' => [$this->user->uuid],
    ]);

    $slots = app(MeetingService::class)->availability($this->user, Carbon::tomorrow());
    $starts = array_map(fn (array $s) => $s['starts_at']->format('H:i'), $slots);

    expect($starts)->not->toContain('10:00')
        ->and($starts)->toContain('09:00');
});

it('availability returns the requested timezone through the API', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

    $response = $this->getJson('/api/v1/meetings/availability?host_uuid='.$this->user->uuid.'&date='.Carbon::tomorrow()->toDateString().'&timezone=Asia/Tehran');

    $response->assertOk();
    expect($response->json('timezone'))->toBe('Asia/Tehran')
        ->and($response->json('slots.0.timezone'))->toBe('Asia/Tehran');
});
