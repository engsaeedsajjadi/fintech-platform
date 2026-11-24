<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class AgreementAccepted extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.agreement_accepted';
    }
}
