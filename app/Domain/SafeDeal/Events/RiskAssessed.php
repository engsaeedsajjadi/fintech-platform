<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class RiskAssessed extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.risk_assessed';
    }
}
