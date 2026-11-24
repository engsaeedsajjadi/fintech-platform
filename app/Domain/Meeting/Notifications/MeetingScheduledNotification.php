<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingScheduledNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('meeting.notifications.scheduled_subject', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.scheduled_line', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.when_line', ['when' => $this->when()]))
            ->action(__('meeting.notifications.view_action'), url('/dashboard/meetings/' . $this->meeting->id));
    }
}
