<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingCancelledNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Meeting cancelled: '.$this->meeting->title)
            ->line('The meeting "'.$this->meeting->title.'" scheduled for '.$this->when().' has been cancelled.')
            ->line($this->meeting->cancel_reason ? 'Reason: '.$this->meeting->cancel_reason : '');
    }
}
