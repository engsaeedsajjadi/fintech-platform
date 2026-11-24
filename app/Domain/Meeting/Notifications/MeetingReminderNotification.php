<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class MeetingReminderNotification extends MeetingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: '.$this->meeting->title.' starts soon')
            ->line('Your meeting "'.$this->meeting->title.'" starts at '.$this->when().'.')
            ->action('Join meeting', $this->meeting->join_url ?? url('/dashboard/meetings/'.$this->meeting->id));
    }
}
