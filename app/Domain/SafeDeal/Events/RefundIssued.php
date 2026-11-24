<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class RefundIssued extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.refund_issued';
    }
}
