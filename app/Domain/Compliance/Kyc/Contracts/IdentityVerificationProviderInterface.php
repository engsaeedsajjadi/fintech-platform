<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Contracts;

/**
 * Identity verification provider — the target-architecture name for the
 * existing {@see KycProviderInterface} seam.
 *
 * This interface EXTENDS KycProviderInterface; it is an alias, not a second
 * seam. OndatoKycProvider / BridgeKycProvider already satisfy the base
 * contract; adapters that can also perform liveness / document checks
 * implement the two added methods. The sandbox adapter implements all of
 * them and always returns `requires_manual_review` — never a pass.
 *
 * Canonical result shape for verifyLiveness() / verifyDocument():
 *
 *   [
 *     'status'   => 'approved'|'rejected'|'pending'|'requires_manual_review'|'not_verified',
 *     'verified' => bool,   // true ONLY for a real provider's approved verdict
 *     'sandbox'  => bool,   // true when the result came from a sandbox adapter
 *     'provider' => string, // stable provider name, e.g. 'ondato', 'sandbox'
 *     'reason'   => ?string,
 *     'metadata' => array,  // provider detail; never raw biometrics or document contents
 *   ]
 *
 * Raw biometrics and document images are NEVER stored by the platform; they
 * are held by the provider. Only the verdict + provider reference are kept.
 */
interface IdentityVerificationProviderInterface extends KycProviderInterface
{
    /**
     * Liveness / biometric check for the given user.
     *
     * @param  array<string, mixed>  $evidence  Provider-specific evidence references
     *                                           (e.g. hosted session id). Never raw image bytes.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyLiveness(int $userId, array $evidence): array;

    /**
     * Document authenticity check for the given user.
     *
     * @param  array<string, mixed>  $document  Document references (type, storage path,
     *                                           provider reference). Never document contents.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyDocument(int $userId, array $document): array;
}
