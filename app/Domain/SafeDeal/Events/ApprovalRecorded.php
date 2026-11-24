<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class ApprovalRecorded extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.approval_recorded';
    }
}
