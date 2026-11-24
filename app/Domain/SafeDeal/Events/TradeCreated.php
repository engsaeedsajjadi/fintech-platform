<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class TradeCreated extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.created';
    }
}
