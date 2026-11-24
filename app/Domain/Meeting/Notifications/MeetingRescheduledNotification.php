<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingRescheduledNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('meeting.notifications.rescheduled_subject', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.rescheduled_line', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.when_line', ['when' => $this->when()]))
            ->action(__('meeting.notifications.view_action'), url('/dashboard/meetings/' . $this->meeting->id));
    }
}
