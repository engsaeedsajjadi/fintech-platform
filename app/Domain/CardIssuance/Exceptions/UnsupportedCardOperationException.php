<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Exceptions;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * Thrown when a CardIssuerInterface operation isn't supported by the active
 * issuer. FinCard has no Apple/Google Pay push provisioning in v1, and its
 * generic createCard needs FinCard-specific metadata (card-type id, amount,
 * cardholder, funded account) — without those inputs it throws here instead
 * of inventing a card. The demo issuer throws it for createCard and
 * getProvisioningData under APP_ENV=production.
 *
 * Client-safe: the message names no provider data, so GraphQL returns it
 * as-is instead of "Internal server error".
 */
final class UnsupportedCardOperationException extends RuntimeException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }
}
