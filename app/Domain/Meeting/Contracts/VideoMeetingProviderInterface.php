<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Contracts;

use App\Domain\Meeting\Exceptions\MeetingProviderNotConfiguredException;
use App\Domain\Meeting\Models\Meeting;

/**
 * Video meeting provider abstraction.
 *
 * Providers never build video infrastructure: they hand back a room name
 * and a join URL for an external video system (Jitsi Meet). A provider
 * that is not configured MUST throw MeetingProviderNotConfiguredException
 * — it must never return a fake room.
 */
interface VideoMeetingProviderInterface
{
    public function getName(): string;

    /**
     * Create (or resolve) a room for the meeting.
     *
     * @return array{provider: string, room_name: string, join_url: string, jwt: bool, sandbox: bool}
     *
     * @throws MeetingProviderNotConfiguredException when the provider has no usable configuration.
     */
    public function createRoom(Meeting $meeting): array;

    /**
     * Join URL for an already-created room. Same not-configured contract.
     */
    public function getJoinUrl(Meeting $meeting): string;

    /**
     * Best-effort room teardown. Jitsi rooms are ephemeral (they vanish
     * when the last participant leaves), so a Jitsi delete is a no-op by
     * design; other providers may do real cleanup here.
     */
    public function deleteRoom(Meeting $meeting): void;
}
