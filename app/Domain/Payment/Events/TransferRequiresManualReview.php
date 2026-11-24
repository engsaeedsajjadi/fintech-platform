<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Payment\Models\PaymentTransaction;

/**
 * Admin-review hook: a transfer needs a human decision (reconciliation
 * mismatch, duplicate webhook with conflicting amounts, compliance hold,
 * or an outcome that stayed `unknown` past its provider SLA).
 *
 * This is an event, not UI: the admin panel (another team's job) listens
 * for it and shows the transfer in a review queue. Nothing in this event
 * settles, reverses, or releases money.
 */
final class TransferRequiresManualReview
{
    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly string $reason,
        public readonly TransactionState $previousState,
    ) {
    }
}
