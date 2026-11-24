<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use Throwable;

/**
 * Exception thrown when a refund is not allowed for a payment.
 */
class RefundNotAllowedException extends PaymentException
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf('Refund not allowed for payment %s: %s', $paymentId, $reason);
        }
        parent::__construct($message, 0, $previous);
    }
}
