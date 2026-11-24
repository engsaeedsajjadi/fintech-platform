<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class CounterpartyVerified extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.counterparty_verified';
    }
}
