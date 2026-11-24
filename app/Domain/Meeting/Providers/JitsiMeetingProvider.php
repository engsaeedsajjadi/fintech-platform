<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Providers;

use App\Domain\Meeting\Contracts\VideoMeetingProviderInterface;
use App\Domain\Meeting\Exceptions\MeetingProviderNotConfiguredException;
use App\Domain\Meeting\Models\Meeting;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

/**
 * Jitsi Meet provider.
 *
 * Jitsi has no server-side "create room" REST endpoint: a room comes into
 * existence when the first participant opens its URL, so this provider
 * builds the join URL from the configured base URL. When an app id and a
 * JWT secret are configured it also signs a JWT following Jitsi's
 * documented token-auth pattern (aud=jitsi, iss=app id, room, exp, and
 * a user context) and appends it as `?jwt=...`. Nothing is invented:
 * without a configured base URL the provider throws instead of
 * returning a room.
 */
final class JitsiMeetingProvider implements VideoMeetingProviderInterface
{
    public function getName(): string
    {
        return 'jitsi';
    }

    public function createRoom(Meeting $meeting): array
    {
        $roomName = $this->roomName($meeting);
        $joinUrl = $this->buildJoinUrl($meeting, $roomName);

        return [
            'provider' => 'jitsi',
            'room_name' => $roomName,
            'join_url' => $joinUrl,
            'jwt' => $this->jwtConfigured(),
            'sandbox' => false,
        ];
    }

    public function getJoinUrl(Meeting $meeting): string
    {
        return $this->buildJoinUrl($meeting, $meeting->room_name ?? $this->roomName($meeting));
    }

    public function deleteRoom(Meeting $meeting): void
    {
        // Jitsi rooms are ephemeral: they disappear when the last
        // participant leaves. There is nothing server-side to delete.
    }

    private function baseUrl(): string
    {
        $baseUrl = (string) config('meeting.jitsi.base_url', '');

        if ($baseUrl === '') {
            throw new MeetingProviderNotConfiguredException(
                'Jitsi is not configured: set JITSI_BASE_URL to a Jitsi Meet deployment URL.'
            );
        }

        return rtrim($baseUrl, '/');
    }

    private function jwtConfigured(): bool
    {
        return (string) config('meeting.jitsi.app_id', '') !== ''
            && (string) config('meeting.jitsi.jwt_secret', '') !== '';
    }

    private function roomName(Meeting $meeting): string
    {
        return 'finaegis-'.Str::lower(Str::substr(str_replace('-', '', (string) $meeting->id), 0, 12));
    }

    private function buildJoinUrl(Meeting $meeting, string $roomName): string
    {
        $url = $this->baseUrl().'/'.$roomName;

        if (! $this->jwtConfigured()) {
            return $url;
        }

        $now = time();
        $payload = [
            'aud' => 'jitsi',
            'iss' => (string) config('meeting.jitsi.app_id'),
            'sub' => $roomName,
            'room' => $roomName,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) config('meeting.jitsi.jwt_ttl', 3600),
            'context' => [
                'user' => [
                    'name' => (string) ($meeting->host?->name ?? 'Guest'),
                    'email' => (string) ($meeting->host?->email ?? ''),
                ],
            ],
        ];

        $token = JWT::encode($payload, (string) config('meeting.jitsi.jwt_secret'), 'HS256');

        return $url.'?jwt='.$token;
    }
}
