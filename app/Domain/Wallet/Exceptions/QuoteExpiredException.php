<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

class QuoteExpiredException extends WalletException
{
    public function __construct(string $quoteId, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Conversion quote {$quoteId} has expired and cannot be executed.");
    }
}
