<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class TradeAudited extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.audited';
    }
}
