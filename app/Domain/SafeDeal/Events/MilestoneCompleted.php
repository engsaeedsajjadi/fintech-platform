<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class MilestoneCompleted extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.milestone_completed';
    }
}
