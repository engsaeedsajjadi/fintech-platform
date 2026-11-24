<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Jobs;

use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Notifications\MeetingReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the pre-meeting reminder to the host and all participants.
 * Idempotent: the meeting is marked reminder_sent_at before notifying,
 * so a retried job does not send twice.
 */
final class SendMeetingReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $meetingId)
    {
    }

    public function handle(): void
    {
        $meeting = Meeting::with(['host', 'participants.user'])->find($this->meetingId);

        if ($meeting === null || ! $meeting->status->isActive()) {
            return;
        }

        if ($meeting->reminder_sent_at !== null) {
            return;
        }

        $meeting->update(['reminder_sent_at' => now()]);

        $meeting->host?->notify(new MeetingReminderNotification($meeting));

        foreach ($meeting->participants as $participant) {
            $participant->user?->notify(new MeetingReminderNotification($meeting));
        }
    }
}
