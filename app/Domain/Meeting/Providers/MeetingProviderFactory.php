<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Providers;

use App\Domain\Meeting\Contracts\VideoMeetingProviderInterface;
use InvalidArgumentException;

final class MeetingProviderFactory
{
    /**
     * @param  array<string, VideoMeetingProviderInterface>  $providers  keyed by provider name
     */
    public function __construct(private readonly array $providers = [])
    {
    }

    public function resolve(?string $name = null): VideoMeetingProviderInterface
    {
        $name ??= (string) config('meeting.default_provider', 'sandbox');

        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        return match ($name) {
            'jitsi' => new JitsiMeetingProvider,
            'sandbox' => new SandboxMeetingProvider,
            default => throw new InvalidArgumentException("Unknown meeting provider: {$name}"),
        };
    }
}
