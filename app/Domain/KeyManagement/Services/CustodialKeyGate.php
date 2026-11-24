<?php

declare(strict_types=1);

namespace App\Domain\KeyManagement\Services;

use App\Domain\KeyManagement\Exceptions\HsmNotConfiguredException;

/**
 * Fail-closed gate for custodial key material.
 *
 * Custodial keys may only be handled when a real HSM/KMS is configured:
 * - config `keymanagement.custodial.enabled` is true,
 * - config `keymanagement.hsm.provider` is 'aws' or 'azure' (never 'demo'),
 * - config `keymanagement.custodial.hsm_key_reference` is non-empty.
 *
 * Anything else throws HsmNotConfiguredException. There is deliberately
 * no fallback to local/demo storage and no fallback to the legacy
 * `encrypted_private_key` database column: without an HSM the custodial
 * path is refused, it is never silently downgraded.
 */
final class CustodialKeyGate
{
    public static function requireHsm(): string
    {
        if (! (bool) config('keymanagement.custodial.enabled', false)) {
            throw new HsmNotConfiguredException('Custodial key storage is not enabled (KEY_MANAGEMENT_CUSTODIAL_ENABLED).');
        }

        $provider = (string) config('keymanagement.hsm.provider', 'demo');
        if (! in_array($provider, ['aws', 'azure'], true)) {
            throw new HsmNotConfiguredException("Custodial key storage requires a real HSM/KMS provider (aws/azure); configured provider is '{$provider}'.");
        }

        $reference = trim((string) config('keymanagement.custodial.hsm_key_reference', ''));
        if ($reference === '') {
            throw new HsmNotConfiguredException('Custodial key storage requires a configured HSM key reference (KEY_MANAGEMENT_CUSTODIAL_HSM_KEY_REFERENCE).');
        }

        return $reference;
    }

    public static function isHsmConfigured(): bool
    {
        try {
            self::requireHsm();

            return true;
        } catch (HsmNotConfiguredException) {
            return false;
        }
    }
}
