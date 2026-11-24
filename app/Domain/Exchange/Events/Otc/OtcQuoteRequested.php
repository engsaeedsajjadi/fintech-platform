<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events\Otc;

/** Dispatched when an OTC quote is issued. Plain domain event (not event-sourced). */
class OtcQuoteRequested
{
    public function __construct(
        public readonly string $quoteId,
        public readonly string $accountId,
        public readonly string $side,
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency,
        public readonly string $amount,
        public readonly string $price,
    ) {
    }
}
