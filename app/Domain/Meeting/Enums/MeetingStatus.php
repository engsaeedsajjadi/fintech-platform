<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Enums;

enum MeetingStatus: string
{
    case SCHEDULED = 'scheduled';
    case CONFIRMED = 'confirmed';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::SCHEDULED, self::CONFIRMED, self::IN_PROGRESS], true);
    }
}
