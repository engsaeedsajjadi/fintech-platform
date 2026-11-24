<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Enums;

enum MilestoneStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
}
