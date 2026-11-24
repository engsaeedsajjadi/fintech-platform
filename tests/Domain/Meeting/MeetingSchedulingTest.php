<?php

declare(strict_types=1);

use App\Domain\Meeting\Enums\MeetingStatus;
use App\Domain\Meeting\Exceptions\MeetingAccessDeniedException;
use App\Domain\Meeting\Exceptions\MeetingConflictException;
use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

function meetingService(): MeetingService
{
    return app(MeetingService::class);
}

function meetingTimes(int $dayOffset = 1, int $startHour = 10, int $durationMinutes = 60): array
{
    $startsAt = Carbon::tomorrow()->addDays($dayOffset - 1)->setTime($startHour, 0);
    $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

    return [$startsAt, $endsAt];
}

it('schedules a meeting with a sandbox room and notifies host and participants', function () {
    $host = $this->user;
    $participant = User::factory()->create();
    [$startsAt, $endsAt] = meetingTimes();

    $meeting = meetingService()->schedule($host, [
        'title' => 'Support call',
        'type' => 'support',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);

    expect($meeting->status)->toBe(MeetingStatus::SCHEDULED)
        ->and($meeting->provider)->toBe('sandbox')
        ->and($meeting->room_name)->toStartWith('sandbox-')
        ->and($meeting->join_url)->toContain('/dashboard/meetings/'.$meeting->id)
        ->and($meeting->participants)->toHaveCount(1)
        ->and($host->notifications()->count())->toBeGreaterThan(0);
});

it('prevents double-booking the host', function () {
    [$startsAt, $endsAt] = meetingTimes();

    meetingService()->schedule($this->user, [
        'title' => 'First', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    expect(fn () => meetingService()->schedule($this->user, [
        'title' => 'Overlap', 'type' => 'support',
        'starts_at' => $startsAt->copy()->addMinutes(30),
        'ends_at' => $endsAt->copy()->addMinutes(30),
    ]))->toThrow(MeetingConflictException::class);
});

it('prevents double-booking a participant', function () {
    $participant = User::factory()->create();
    [$startsAt, $endsAt] = meetingTimes();

    meetingService()->schedule($this->user, [
        'title' => 'First', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);

    // The participant, now hosting their own overlapping meeting, conflicts.
    expect(fn () => meetingService()->schedule($participant, [
        'title' => 'Overlap', 'type' => 'support',
        'starts_at' => $startsAt->copy()->addMinutes(30),
        'ends_at' => $endsAt->copy()->addMinutes(30),
    ]))->toThrow(MeetingConflictException::class);
});

it('allows back-to-back meetings and meetings for other users', function () {
    [$startsAt, $endsAt] = meetingTimes();

    meetingService()->schedule($this->user, [
        'title' => 'First', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    $second = meetingService()->schedule($this->user, [
        'title' => 'Second', 'type' => 'support', 'starts_at' => $endsAt, 'ends_at' => $endsAt->copy()->addHour(),
    ]);

    $other = meetingService()->schedule(User::factory()->create(), [
        'title' => 'Other', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    expect($second->status)->toBe(MeetingStatus::SCHEDULED)
        ->and($other->status)->toBe(MeetingStatus::SCHEDULED);
});

it('requires approved KYC for video-KYC meetings', function () {
    [$startsAt, $endsAt] = meetingTimes();
    expect($this->user->kyc_status)->not->toBe('approved');

    expect(fn () => meetingService()->schedule($this->user, [
        'title' => 'Video KYC', 'type' => 'video_kyc', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]))->toThrow(MeetingAccessDeniedException::class);

    $this->user->update(['kyc_status' => 'approved']);

    $meeting = meetingService()->schedule($this->user->refresh(), [
        'title' => 'Video KYC', 'type' => 'video_kyc', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    expect($meeting->type->value)->toBe('video_kyc');
});

it('cancels and reschedules a meeting', function () {
    [$startsAt, $endsAt] = meetingTimes();

    $meeting = meetingService()->schedule($this->user, [
        'title' => 'To move', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    $newStart = $startsAt->copy()->addDay();
    $newEnd = $endsAt->copy()->addDay();

    $moved = meetingService()->reschedule($meeting, $this->user, $newStart, $newEnd);

    expect($moved->starts_at->equalTo($newStart))->toBeTrue()
        ->and($moved->status)->toBe(MeetingStatus::SCHEDULED);

    $cancelled = meetingService()->cancel($moved, $this->user, 'No longer needed');

    expect($cancelled->status)->toBe(MeetingStatus::CANCELLED)
        ->and($cancelled->cancel_reason)->toBe('No longer needed')
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

it('refuses rescheduling into a conflicting slot', function () {
    [$startsAt, $endsAt] = meetingTimes();

    $first = meetingService()->schedule($this->user, [
        'title' => 'First', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
    meetingService()->schedule($this->user, [
        'title' => 'Second', 'type' => 'support',
        'starts_at' => $endsAt->copy()->addHour(), 'ends_at' => $endsAt->copy()->addHours(2),
    ]);

    expect(fn () => meetingService()->reschedule(
        $first, $this->user, $endsAt->copy()->addMinutes(30), $endsAt->copy()->addMinutes(90)
    ))->toThrow(MeetingConflictException::class);
});

it('lets participants join but blocks outsiders', function () {
    $participant = User::factory()->create();
    $outsider = User::factory()->create();
    [$startsAt, $endsAt] = meetingTimes();

    $meeting = meetingService()->schedule($this->user, [
        'title' => 'Private', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);

    expect(meetingService()->join($meeting, $this->user))->toContain('/dashboard/meetings/')
        ->and(meetingService()->join($meeting, $participant))->toContain('/dashboard/meetings/')
        ->and(fn () => meetingService()->join($meeting, $outsider))->toThrow(MeetingAccessDeniedException::class);
});

it('lets staff join any meeting', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    [$startsAt, $endsAt] = meetingTimes();

    $meeting = meetingService()->schedule($this->user, [
        'title' => 'Private', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    expect(meetingService()->join($meeting, $admin))->toContain('/dashboard/meetings/');
});

it('stores chat messages only for participants', function () {
    $participant = User::factory()->create();
    $outsider = User::factory()->create();
    [$startsAt, $endsAt] = meetingTimes();

    $meeting = meetingService()->schedule($this->user, [
        'title' => 'Chatty', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);

    $message = meetingService()->addMessage($meeting, $participant, 'Hello from the participant');

    expect($message->body)->toBe('Hello from the participant')
        ->and(meetingService()->listMessages($meeting, $this->user))->toHaveCount(1)
        ->and(fn () => meetingService()->addMessage($meeting, $outsider, 'Nope'))->toThrow(MeetingAccessDeniedException::class);
});

it('returns free availability slots excluding busy times', function () {
    $tomorrow = Carbon::tomorrow();
    [$startsAt, $endsAt] = meetingTimes(1, 10);

    meetingService()->schedule($this->user, [
        'title' => 'Busy', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);

    $slots = meetingService()->availability($this->user, $tomorrow);
    $slotStarts = array_map(fn (array $s) => $s['starts_at']->format('H:i'), $slots);

    expect($slotStarts)->not->toContain('10:00')
        ->and($slotStarts)->not->toContain('10:30')
        ->and($slotStarts)->toContain('09:00')
        ->and($slotStarts)->toContain('11:00');
});

it('rejects invalid meeting times', function () {
    [$startsAt, $endsAt] = meetingTimes();

    expect(fn () => meetingService()->schedule($this->user, [
        'title' => 'Bad', 'type' => 'support', 'starts_at' => $endsAt, 'ends_at' => $startsAt,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => meetingService()->schedule($this->user, [
        'title' => 'Past', 'type' => 'support',
        'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30),
    ]))->toThrow(InvalidArgumentException::class);
});
