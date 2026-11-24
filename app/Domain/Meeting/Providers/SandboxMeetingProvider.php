<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Providers;

use App\Domain\Meeting\Contracts\VideoMeetingProviderInterface;
use App\Domain\Meeting\Models\Meeting;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sandbox provider for local development and tests.
 *
 * Returns clearly-labelled fake rooms under the application's own URL.
 * It is never a real video call, and it refuses to run in production.
 */
final class SandboxMeetingProvider implements VideoMeetingProviderInterface
{
    public function __construct()
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Sandbox meeting provider must not be used in production.');
        }
    }

    public function getName(): string
    {
        return 'sandbox';
    }

    public function createRoom(Meeting $meeting): array
    {
        $roomName = $this->roomName($meeting);

        return [
            'provider' => 'sandbox',
            'room_name' => $roomName,
            'join_url' => $this->joinUrl($meeting, $roomName),
            'jwt' => false,
            'sandbox' => true,
        ];
    }

    public function getJoinUrl(Meeting $meeting): string
    {
        return $this->joinUrl($meeting, $meeting->room_name ?? $this->roomName($meeting));
    }

    public function deleteRoom(Meeting $meeting): void
    {
        // Sandbox rooms hold no external state.
    }

    private function roomName(Meeting $meeting): string
    {
        return 'sandbox-'.Str::lower(Str::substr(str_replace('-', '', (string) $meeting->id), 0, 12));
    }

    private function joinUrl(Meeting $meeting, string $roomName): string
    {
        // The sandbox room has no video backend, so the join URL is the
        // meeting's own page, which displays the sandbox notice.
        // $roomName is kept in the response payload for debugging.
        return rtrim((string) config('app.url'), '/').'/dashboard/meetings/'.$meeting->id;
    }
}
