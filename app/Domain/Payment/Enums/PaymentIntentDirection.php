<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/**
 * Which way money moves for a payment intent, independent of provider.
 *
 * - internal: between two accounts on this platform.
 * - external: between this platform and an outside provider/rail.
 * - incoming: money arriving (deposit, incoming transfer, refund received).
 * - outgoing: money leaving (withdrawal, outgoing transfer, refund sent).
 *
 * A refund is its own intent (kind=refund) with the opposite direction
 * of the original; a reversal is kind=reversal linked to the original.
 */
enum PaymentIntentDirection: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';
}
