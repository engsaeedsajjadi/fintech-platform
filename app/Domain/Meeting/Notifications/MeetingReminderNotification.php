<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingReminderNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('meeting.notifications.reminder_subject', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.reminder_line', ['title' => $this->meeting->title, 'when' => $this->when()]))
            ->action(__('meeting.join'), $this->meeting->join_url ?? url('/dashboard/meetings/' . $this->meeting->id));
    }
}
