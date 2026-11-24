<?php

declare(strict_types=1);

use App\Domain\Meeting\Enums\ParticipantStatus;
use App\Domain\Meeting\Exceptions\MeetingAccessDeniedException;
use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

function invitationMeeting(User $host, User $participant)
{
    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];

    return app(MeetingService::class)->schedule($host, [
        'title' => 'Invite test', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [$participant->uuid],
    ]);
}

it('lets an invited participant accept and decline', function () {
    $participant = User::factory()->create();
    $meeting = invitationMeeting($this->user, $participant);

    $accepted = app(MeetingService::class)->respondToInvitation($meeting, $participant, ParticipantStatus::ACCEPTED);
    expect($accepted->status)->toBe(ParticipantStatus::ACCEPTED);

    $declined = app(MeetingService::class)->respondToInvitation($meeting, $participant, ParticipantStatus::DECLINED);
    expect($declined->status)->toBe(ParticipantStatus::DECLINED);
});

it('blocks outsiders and the host from responding to an invitation', function () {
    $participant = User::factory()->create();
    $outsider = User::factory()->create();
    $meeting = invitationMeeting($this->user, $participant);

    expect(fn () => app(MeetingService::class)->respondToInvitation($meeting, $outsider, ParticipantStatus::ACCEPTED))
        ->toThrow(MeetingAccessDeniedException::class);
    expect(fn () => app(MeetingService::class)->respondToInvitation($meeting, $this->user, ParticipantStatus::ACCEPTED))
        ->toThrow(MeetingAccessDeniedException::class);
});

it('blocks invitation responses on a cancelled meeting', function () {
    $participant = User::factory()->create();
    $meeting = invitationMeeting($this->user, $participant);
    app(MeetingService::class)->cancel($meeting, $this->user);

    expect(fn () => app(MeetingService::class)->respondToInvitation($meeting, $participant, ParticipantStatus::ACCEPTED))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects scheduling with an unknown participant uuid instead of creating an orphan row', function () {
    [$startsAt, $endsAt] = [Carbon::tomorrow()->setTime(10, 0), Carbon::tomorrow()->setTime(11, 0)];

    expect(fn () => app(MeetingService::class)->schedule($this->user, [
        'title' => 'Ghost', 'type' => 'support', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        'participant_uuids' => [(string) Str::uuid()],
    ]))->toThrow(InvalidArgumentException::class);

    expect(\App\Domain\Meeting\Models\Meeting::count())->toBe(0);
});

it('accepts an invitation through the API', function () {
    $participant = User::factory()->create();
    $meeting = invitationMeeting($this->user, $participant);

    \Laravel\Sanctum\Sanctum::actingAs($participant, ['read', 'write', 'delete']);

    $response = $this->postJson('/api/v1/meetings/'.$meeting->id.'/respond', ['status' => 'accepted']);

    $response->assertOk();
    expect($meeting->participants()->first()->status)->toBe(ParticipantStatus::ACCEPTED);
});

it('rejects an invalid invitation response through the API', function () {
    $participant = User::factory()->create();
    $meeting = invitationMeeting($this->user, $participant);

    \Laravel\Sanctum\Sanctum::actingAs($participant, ['read', 'write', 'delete']);

    $this->postJson('/api/v1/meetings/'.$meeting->id.'/respond', ['status' => 'maybe'])
        ->assertStatus(422);
});
