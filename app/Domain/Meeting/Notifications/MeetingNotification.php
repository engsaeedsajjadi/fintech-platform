<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Support\JalaliDateHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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

    protected function when(): string
    {
        return JalaliDateHelper::format($this->meeting->starts_at, false)
            .' ('.$this->meeting->starts_at->format('Y-m-d H:i').')';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'meeting_id' => $this->meeting->id,
            'title' => $this->meeting->title,
            'type' => $this->meeting->type->value,
            'status' => $this->meeting->status->value,
            'starts_at' => $this->meeting->starts_at->toIso8601String(),
            'join_url' => $this->meeting->join_url,
        ];
    }
}
