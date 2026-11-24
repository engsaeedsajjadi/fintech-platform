<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exceptions;

/**
 * Thrown by the bank-account opening workflow: KYC gate failures, provider
 * rejections, unknown provider outcomes (→ manual review), and capability
 * errors (e.g. closure on a provider that does not support it).
 */
class AccountOpeningException extends BankOperationException
{
}
