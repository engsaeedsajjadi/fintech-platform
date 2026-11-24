<?php

declare(strict_types=1);

use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;

// No frontend build (public/build/manifest.json) exists in this checkout,
// so @vite in the app layout would 500 every rendered page. Bypass Vite
// the standard Laravel way; this tests routes + views, not the asset build.
beforeEach(function () {
    $this->withoutVite();
});

it('renders the customer dashboard hub with honest section statuses', function () {
    $response = $this->actingAs($this->user)->get(route('dashboard.hub'));

    $response->assertOk();
    $response->assertSee(__('dashboard.hub_title'));
    $response->assertSee(__('dashboard.section_meetings'));
    $response->assertSee(__('dashboard.section_trading'));
    $response->assertSee(__('dashboard.status_live'));
});

it('renders the meeting calendar and meeting page for a participant', function () {
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Web test meeting', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $this->actingAs($this->user)->get(route('dashboard.meetings.index'))
        ->assertOk()
        ->assertSee('Web test meeting');

    $this->actingAs($this->user)->get(route('dashboard.meetings.show', $meeting))
        ->assertOk()
        ->assertSee('Web test meeting');
});

it('blocks outsiders from a meeting page', function () {
    $outsider = User::factory()->create();
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Private web meeting', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $this->actingAs($outsider)->get(route('dashboard.meetings.show', $meeting))
        ->assertForbidden();
});
