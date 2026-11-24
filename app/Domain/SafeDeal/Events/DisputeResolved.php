<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class DisputeResolved extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.dispute_resolved';
    }
}
