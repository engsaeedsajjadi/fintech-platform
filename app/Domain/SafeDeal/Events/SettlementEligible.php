<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class SettlementEligible extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.settlement_eligible';
    }
}
