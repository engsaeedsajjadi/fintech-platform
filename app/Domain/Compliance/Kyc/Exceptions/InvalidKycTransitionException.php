<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Exceptions;

use LogicException;

/** Thrown when a KYC status transition is not allowed from the current state. */
final class InvalidKycTransitionException extends LogicException
{
    public static function from(string $from, string $to, string $reason = ''): self
    {
        $message = sprintf(
            'KYC status cannot move from "%s" to "%s".',
            $from,
            $to
        );

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message);
    }
}
