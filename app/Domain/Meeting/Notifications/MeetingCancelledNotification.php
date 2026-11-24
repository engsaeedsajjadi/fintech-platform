<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingCancelledNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('meeting.notifications.cancelled_subject', ['title' => $this->meeting->title]))
            ->line(__('meeting.notifications.cancelled_line', ['title' => $this->meeting->title, 'when' => $this->when()]))
            ->line($this->meeting->cancel_reason ? __('meeting.notifications.reason_line', ['reason' => $this->meeting->cancel_reason]) : '');
    }
}
