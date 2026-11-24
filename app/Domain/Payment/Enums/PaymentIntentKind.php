<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/**
 * What a payment intent is for. Deposits, withdrawals and transfers are
 * the primary movements; refunds and reversals are separate intents that
 * reference the original via `reverses_intent_id` — a terminal intent is
 * never edited in place, a correction is a new intent.
 */
enum PaymentIntentKind: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case TRANSFER = 'transfer';
    case REFUND = 'refund';
    case REVERSAL = 'reversal';
}
