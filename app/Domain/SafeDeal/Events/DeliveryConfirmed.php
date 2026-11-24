<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

class DeliveryConfirmed extends SafeDealEvent
{
    public function name(): string
    {
        return 'trade.delivery_confirmed';
    }
}
