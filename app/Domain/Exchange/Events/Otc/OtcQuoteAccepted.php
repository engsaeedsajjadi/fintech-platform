<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events\Otc;

/** Dispatched when an OTC quote is accepted (before settlement completes). */
class OtcQuoteAccepted
{
    public function __construct(
        public readonly string $quoteId,
        public readonly string $accountId,
        public readonly string $dealId,
    ) {
    }
}
