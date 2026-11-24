<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Exceptions;

use RuntimeException;

class OtcQuoteException extends RuntimeException
{
    public static function notFound(string $quoteId): self
    {
        return new self("OTC quote not found: {$quoteId}");
    }

    public static function expired(string $quoteId): self
    {
        return new self("OTC quote expired: {$quoteId}");
    }

    public static function notAcceptable(string $quoteId, string $status): self
    {
        return new self("OTC quote {$quoteId} cannot be accepted in status: {$status}");
    }
}
