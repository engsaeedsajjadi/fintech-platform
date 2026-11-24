<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Support\LocalizedDateHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class MeetingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected readonly Meeting $meeting)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Locale-aware start time: Jalali + Persian digits for `fa`,
     * Gregorian for `en`. The notification is rendered in the
     * recipient's locale when the notification is sent through a
     * locale-aware channel; the app locale is used otherwise.
     */
    protected function when(): string
    {
        return LocalizedDateHelper::format($this->meeting->starts_at);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'meeting_id' => $this->meeting->id,
            'title'      => $this->meeting->title,
            'type'       => $this->meeting->type->value,
            'status'     => $this->meeting->status->value,
            'starts_at'  => $this->meeting->starts_at->toIso8601String(),
            'join_url'   => $this->meeting->join_url,
        ];
    }
}
