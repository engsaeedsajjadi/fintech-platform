<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Http\Controllers;

use App\Domain\Meeting\Enums\ParticipantStatus;
use App\Domain\Meeting\Exceptions\MeetingAccessDeniedException;
use App\Domain\Meeting\Exceptions\MeetingConflictException;
use App\Domain\Meeting\Exceptions\MeetingProviderNotConfiguredException;
use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Services\MeetingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MeetingController
{
    public function __construct(private readonly MeetingService $meetings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $meetings = Meeting::with(['host', 'participants.user'])
            ->where(function ($query) use ($user) {
                $query->where('host_uuid', $user->uuid)
                    ->orWhereHas('participants', fn ($q) => $q->where('user_uuid', $user->uuid));
            })
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json($meetings);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'type'                => ['nullable', 'string', 'in:video_kyc,support,advisory,onboarding'],
            'starts_at'           => ['required', 'date'],
            'ends_at'             => ['required', 'date'],
            'participant_uuids'   => ['nullable', 'array'],
            'participant_uuids.*' => ['string', 'exists:users,uuid'],
            'provider'            => ['nullable', 'string', 'in:sandbox,jitsi'],
            'timezone'            => ['nullable', 'string', 'timezone'],
        ]);

        try {
            $meeting = $this->meetings->schedule($request->user(), [
                'title'             => $validated['title'],
                'description'       => $validated['description'] ?? null,
                'type'              => $validated['type'] ?? 'support',
                'starts_at'         => Carbon::parse($validated['starts_at']),
                'ends_at'           => Carbon::parse($validated['ends_at']),
                'participant_uuids' => $validated['participant_uuids'] ?? [],
                'provider'          => $validated['provider'] ?? null,
                'timezone'          => $validated['timezone'] ?? null,
            ]);
        } catch (MeetingProviderNotConfiguredException $e) {
            throw ValidationException::withMessages(['provider' => __('meeting.provider_not_configured')]);
        } catch (MeetingConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => __('meeting.conflict')]);
        } catch (MeetingAccessDeniedException $e) {
            throw ValidationException::withMessages(['meeting' => __('meeting.access_denied')]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['starts_at' => __('meeting.invalid_time')]);
        }

        return response()->json($meeting->load(['host', 'participants.user']), 201);
    }

    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        $this->assertVisible($request, $meeting);

        return response()->json($meeting->load(['host', 'participants.user']));
    }

    public function cancel(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $meeting = $this->meetings->cancel($meeting, $request->user(), $validated['reason'] ?? null);
        } catch (MeetingAccessDeniedException $e) {
            throw ValidationException::withMessages(['meeting' => __('meeting.access_denied')]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['meeting' => __('meeting.invalid_time')]);
        }

        return response()->json($meeting);
    }

    /** Accept or decline the authenticated user's invitation. */
    public function respond(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,declined'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $participant = $this->meetings->respondToInvitation(
                $meeting,
                $user,
                ParticipantStatus::from($validated['status'])
            );
        } catch (MeetingAccessDeniedException $e) {
            return response()->json(['message' => __('meeting.access_denied')], 403);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => __('meeting.invalid_time')]);
        }

        return response()->json($participant);
    }

    public function reschedule(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date'],
            'timezone'  => ['nullable', 'string', 'timezone'],
        ]);

        try {
            $meeting = $this->meetings->reschedule(
                $meeting,
                $request->user(),
                Carbon::parse($validated['starts_at']),
                Carbon::parse($validated['ends_at']),
                $validated['timezone'] ?? null
            );
        } catch (MeetingConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => __('meeting.conflict')]);
        } catch (MeetingAccessDeniedException $e) {
            throw ValidationException::withMessages(['meeting' => __('meeting.access_denied')]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['starts_at' => __('meeting.invalid_time')]);
        }

        return response()->json($meeting);
    }

    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        try {
            $joinUrl = $this->meetings->join($meeting, $request->user());
        } catch (MeetingAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['join_url' => $joinUrl, 'room_name' => $meeting->room_name, 'sandbox' => $meeting->provider === 'sandbox']);
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host_uuid' => ['required', 'string', 'exists:users,uuid'],
            'date'      => ['required', 'date'],
            'timezone'  => ['nullable', 'string', 'timezone'],
        ]);

        $host = User::where('uuid', $validated['host_uuid'])->firstOrFail();
        $slots = $this->meetings->availability($host, Carbon::parse($validated['date']), $validated['timezone'] ?? null);

        return response()->json([
            'date'     => $validated['date'],
            'timezone' => $slots[0]['timezone'] ?? ($validated['timezone'] ?? (string) config('app.timezone', 'UTC')),
            'slots'    => array_map(fn (array $slot) => [
                'starts_at' => $slot['starts_at']->toIso8601String(),
                'ends_at'   => $slot['ends_at']->toIso8601String(),
                'timezone'  => $slot['timezone'],
            ], $slots),
        ]);
    }

    public function messages(Request $request, Meeting $meeting): JsonResponse
    {
        try {
            return response()->json($this->meetings->listMessages($meeting, $request->user()));
        } catch (MeetingAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function storeMessage(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        try {
            $message = $this->meetings->addMessage($meeting, $request->user(), $validated['body']);
        } catch (MeetingAccessDeniedException $e) {
            return response()->json(['message' => __('meeting.access_denied')], 403);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        return response()->json($message, 201);
    }

    private function assertVisible(Request $request, Meeting $meeting): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $meeting->isParticipant($user) && ! $user->hasRole('admin')) {
            throw ValidationException::withMessages(['meeting' => __('meeting.not_participant')]);
        }
    }
}
