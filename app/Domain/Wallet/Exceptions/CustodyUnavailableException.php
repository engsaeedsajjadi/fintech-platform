<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

class CustodyUnavailableException extends WalletException
{
    public function __construct(string $message = 'No licensed custodian is configured; custodial settlement is unavailable.')
    {
        parent::__construct($message);
    }
}
