<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Models\PaymentTransaction;

/**
 * Admin-review hook: a transfer was reversed. The original journal entry
 * was NOT edited — a mirror (reversing) entry was posted and its id is in
 * $reversalEntryId; the original entry's status is REVERSED.
 */
final class TransferReversed
{
    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly string $reversalEntryId,
        public readonly string $reason,
    ) {
    }
}
