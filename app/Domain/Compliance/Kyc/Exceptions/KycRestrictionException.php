<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Exceptions;

use RuntimeException;

/** Thrown when a user attempts an action their KYC verification status does not allow. */
final class KycRestrictionException extends RuntimeException
{
    public static function for(string $action, string $kycStatus): self
    {
        return new self(sprintf(
            'Action "%s" is not allowed while KYC status is "%s". Complete verification first.',
            $action,
            $kycStatus,
        ));
    }
}
