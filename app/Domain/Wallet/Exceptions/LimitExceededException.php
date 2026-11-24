<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

class LimitExceededException extends WalletException
{
    public function __construct(string $operation, string $limit, string $attempted, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Wallet limit exceeded for {$operation}: attempted {$attempted}, limit {$limit}.");
    }
}
