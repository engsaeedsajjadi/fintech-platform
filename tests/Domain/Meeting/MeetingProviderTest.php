<?php

declare(strict_types=1);

use App\Domain\Meeting\Exceptions\MeetingProviderNotConfiguredException;
use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Providers\JitsiMeetingProvider;
use App\Domain\Meeting\Providers\MeetingProviderFactory;
use App\Domain\Meeting\Providers\SandboxMeetingProvider;
use App\Models\User;
use Illuminate\Support\Str;

function bareMeeting(User $host): Meeting
{
    return new Meeting([
        'id' => (string) Str::uuid(),
        'host_uuid' => $host->uuid,
        'title' => 'Test',
    ]);
}

it('jitsi provider throws an explicit error when unconfigured', function () {
    config(['meeting.jitsi.base_url' => '']);

    expect(fn () => (new JitsiMeetingProvider)->createRoom(bareMeeting($this->user)))
        ->toThrow(MeetingProviderNotConfiguredException::class, 'JITSI_BASE_URL');
});

it('jitsi provider builds a join URL from the configured base URL', function () {
    config(['meeting.jitsi.base_url' => 'https://meet.example.test', 'meeting.jitsi.app_id' => '', 'meeting.jitsi.jwt_secret' => '']);

    $room = (new JitsiMeetingProvider)->createRoom(bareMeeting($this->user));

    expect($room['provider'])->toBe('jitsi')
        ->and($room['room_name'])->toStartWith('finaegis-')
        ->and($room['join_url'])->toStartWith('https://meet.example.test/finaegis-')
        ->and($room['jwt'])->toBeFalse()
        ->and($room['sandbox'])->toBeFalse();
});

it('jitsi provider signs a JWT when app id and secret are configured', function () {
    config([
        'meeting.jitsi.base_url' => 'https://meet.example.test',
        'meeting.jitsi.app_id' => 'finaegis-app',
        'meeting.jitsi.jwt_secret' => 'test-secret-that-is-long-enough-32',
    ]);

    $room = (new JitsiMeetingProvider)->createRoom(bareMeeting($this->user));

    expect($room['jwt'])->toBeTrue()
        ->and($room['join_url'])->toContain('?jwt=');

    $token = Str::after($room['join_url'], '?jwt=');
    $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key('test-secret-that-is-long-enough-32', 'HS256'));

    expect($decoded->aud)->toBe('jitsi')
        ->and($decoded->iss)->toBe('finaegis-app')
        ->and($decoded->room)->toBe($room['room_name']);
});

it('sandbox provider works and never pretends to be a real call', function () {
    $meeting = bareMeeting($this->user);

    $room = (new SandboxMeetingProvider)->createRoom($meeting);

    expect($room['provider'])->toBe('sandbox')
        ->and($room['sandbox'])->toBeTrue()
        ->and($room['join_url'])->toBe(config('app.url').'/dashboard/meetings/'.$meeting->id)
        ->and((new SandboxMeetingProvider)->getJoinUrl($meeting))->toBe($room['join_url']);
});

it('provider factory resolves sandbox by default and rejects unknown providers', function () {
    config(['meeting.default_provider' => 'sandbox']);

    expect((new MeetingProviderFactory)->resolve())->toBeInstanceOf(SandboxMeetingProvider::class)
        ->and((new MeetingProviderFactory)->resolve('jitsi'))->toBeInstanceOf(JitsiMeetingProvider::class)
        ->and(fn () => (new MeetingProviderFactory)->resolve('zoom'))->toThrow(InvalidArgumentException::class);
});

it('scheduling with jitsi unconfigured fails explicitly instead of creating a fake room', function () {
    config(['meeting.jitsi.base_url' => '']);
    $startsAt = now()->addDay()->setTime(10, 0);
    $endsAt = $startsAt->copy()->addHour();

    expect(fn () => app(\App\Domain\Meeting\Services\MeetingService::class)->schedule($this->user, [
        'title' => 'Jitsi test', 'type' => 'support',
        'starts_at' => $startsAt, 'ends_at' => $endsAt, 'provider' => 'jitsi',
    ]))->toThrow(MeetingProviderNotConfiguredException::class);

    expect(Meeting::count())->toBe(0);
});
