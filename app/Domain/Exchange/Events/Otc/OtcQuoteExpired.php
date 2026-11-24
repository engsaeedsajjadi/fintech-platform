<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events\Otc;

/** Dispatched when an OTC quote expires unaccepted. */
class OtcQuoteExpired
{
    public function __construct(
        public readonly string $quoteId,
        public readonly string $accountId,
    ) {
    }
}
