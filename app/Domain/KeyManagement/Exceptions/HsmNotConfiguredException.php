<?php

declare(strict_types=1);

namespace App\Domain\KeyManagement\Exceptions;

use RuntimeException;

/**
 * Thrown when custodial key material is requested but no real HSM/KMS is
 * configured. Fail-closed by design: there is no fallback to local/demo
 * key storage for custodial keys.
 */
class HsmNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Custodial key storage requires a configured HSM/KMS (aws/azure) and an enabled custodial key reference. No HSM is configured, so the operation is refused.')
    {
        parent::__construct($message);
    }
}
