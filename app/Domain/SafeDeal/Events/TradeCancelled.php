<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class TradeCancelled extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.cancelled';
    }
}
