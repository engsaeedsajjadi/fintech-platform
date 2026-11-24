<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events\Otc;

/** Dispatched after an OTC deal settles in the ledger. */
class OtcDealSettled
{
    public function __construct(
        public readonly string $dealId,
        public readonly string $quoteId,
        public readonly string $accountId,
        /** @var array<int, string> */
        public readonly array $ledgerEntryNumbers,
    ) {
    }
}
