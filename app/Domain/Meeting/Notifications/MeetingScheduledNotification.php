<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingScheduledNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Meeting scheduled: '.$this->meeting->title)
            ->line('A meeting has been scheduled: '.$this->meeting->title)
            ->line('When: '.$this->when())
            ->action('View meeting', url('/dashboard/meetings/'.$this->meeting->id));
    }
}
