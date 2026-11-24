<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class TradeSettled extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.settled';
    }
}
