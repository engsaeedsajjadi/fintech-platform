<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class PaymentConfirmed extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.payment_confirmed';
    }
}
