<?php

declare(strict_types=1);

use App\Domain\Meeting\Services\MeetingService;
use Carbon\Carbon;

// No frontend build (public/build/manifest.json) exists in this checkout,
// so @vite in the app layout would 500 every rendered page. Bypass Vite
// the standard Laravel way; this tests routes + views, not the asset build.
beforeEach(function () {
    $this->withoutVite();
});

it('switches locale through the switcher and persists it in the session', function () {
    $response = $this->actingAs($this->user)
        ->from(route('dashboard.hub'))
        ->post(route('locale.switch', 'fa'));

    $response->assertRedirect(route('dashboard.hub'));
    expect(session('locale'))->toBe('fa');

    // An unsupported locale is ignored, not stored.
    $this->actingAs($this->user)->post(route('locale.switch', 'xx'));
    expect(session('locale'))->toBe('fa');
});

it('renders the fa dashboard right-to-left with Persian strings and Jalali dates', function () {
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    app(MeetingService::class)->schedule($this->user, [
        'title' => 'جلسه آزمایشی', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $response = $this->actingAs($this->user)
        ->withSession(['locale' => 'fa'])
        ->get(route('dashboard.meetings.index'));

    $response->assertOk();
    $content = $response->getContent();
    expect($content)->toContain('dir="rtl"')
        ->and($content)->toContain('lang="fa"')
        ->and($content)->toContain('جلسات')
        ->and($content)->toContain('۱۴۰۵') // Jalali year, Persian digits
        ->and($content)->not->toContain('dir="ltr"');

    $hub = $this->actingAs($this->user)->withSession(['locale' => 'fa'])->get(route('dashboard.hub'));
    $hub->assertOk()->assertSee('داشبورد مشتری');
});

it('renders the en dashboard left-to-right with Gregorian dates', function () {
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    app(MeetingService::class)->schedule($this->user, [
        'title' => 'English meeting', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $response = $this->actingAs($this->user)
        ->withSession(['locale' => 'en'])
        ->get(route('dashboard.meetings.index'));

    $response->assertOk();
    $content = $response->getContent();
    expect($content)->toContain('dir="ltr"')
        ->and($content)->toContain('Meetings')
        ->and($content)->toContain($startsAt->format('F Y')) // e.g. "November 2026"
        ->and($content)->not->toContain('۱۴۰۵')
        ->and($content)->not->toContain('dir="rtl"');
});

it('renders the meeting page in fa with a Jalali date and the sandbox notice', function () {
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'صفحه جلسه', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $this->actingAs($this->user)->withSession(['locale' => 'fa'])
        ->get(route('dashboard.meetings.show', $meeting))
        ->assertOk()
        ->assertSee('۱۴۰۵')
        ->assertSee('این یک اتاق آزمایشی (Sandbox) است');
});

it('returns validation errors in Persian for fa API clients', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/meetings', [
        'type' => 'support',
        'starts_at' => 'not-a-date',
        'ends_at' => 'not-a-date',
    ], ['Accept-Language' => 'fa']);

    $response->assertStatus(422);
    $errors = $response->json('errors');
    expect($errors['title'][0])->toContain('الزامی')
        ->and($errors['starts_at'][0])->toContain('تاریخ معتبر');
});

it('returns validation errors in English for en API clients', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/meetings', [
        'type' => 'support',
        'starts_at' => 'not-a-date',
        'ends_at' => 'not-a-date',
    ], ['Accept-Language' => 'en']);

    $response->assertStatus(422);
    $errors = $response->json('errors');
    expect($errors['title'][0])->toContain('required')
        ->and($errors['starts_at'][0])->toContain('valid date');
});

it('falls back to English for an unsupported Accept-Language', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/meetings', [
        'type' => 'support',
        'starts_at' => 'not-a-date',
        'ends_at' => 'not-a-date',
    ], ['Accept-Language' => 'xx']);

    $response->assertStatus(422);
    expect($response->json('errors.title.0'))->toContain('required');
});

it('accepts an invitation from the web meeting page', function () {
    $participant = \App\Models\User::factory()->create();
    $startsAt = Carbon::tomorrow()->setTime(10, 0);
    $meeting = app(MeetingService::class)->schedule($this->user, [
        'title' => 'Web respond', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
        'participant_uuids' => [$participant->uuid],
    ]);

    $this->actingAs($participant)
        ->post(route('dashboard.meetings.respond', $meeting), ['status' => 'accepted'])
        ->assertRedirect();

    expect($meeting->participants()->first()->status->value)->toBe('accepted');
});
