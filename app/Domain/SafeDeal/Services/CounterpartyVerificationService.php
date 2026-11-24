<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Services;

use App\Domain\SafeDeal\Enums\VerificationStatus;
use App\Domain\SafeDeal\Exceptions\SafeDealException;
use App\Models\User;

/**
 * Counterparty identification + verification.
 *
 * SIMULATION ONLY: verification is recorded from an explicit operator /
 * counterparty call carrying an external KYC reference (e.g. an Ondato /
 * TrustCert reference id). This service never calls a live KYC provider
 * and never invents a verification: a party stays UNVERIFIED until a
 * reference is supplied, and high-value trades fail closed when either
 * party lacks ENHANCED verification.
 *
 * Phase 8 security hardening: the reference alone is NOT a verification.
 * Before this fix any authenticated party could self-assert VERIFIED by
 * posting an arbitrary string (e.g. "fake-123") as `kyc_reference` and
 * pass the funding gate. Now the reference is only recorded as evidence
 * when the party's PLATFORM KYC is already approved (users.kyc_status =
 * 'approved', set by KycService from a real provider verdict or a manual
 * review decision) — and level ENHANCED additionally requires a platform
 * KYC level of 'enhanced' or 'full'. A party without approved platform
 * KYC fails closed and stays UNVERIFIED.
 */
class CounterpartyVerificationService
{
    public const BASIC = 'basic';

    public const ENHANCED = 'enhanced';

    /**
     * Amount (in trade currency, decimal string) at/above which ENHANCED
     * verification is required for BOTH parties. Fail-closed default.
     */
    public static function enhancedThreshold(): string
    {
        return (string) config('safedeal.enhanced_verification_threshold', '10000.0000');
    }

    /**
     * @return array{status: VerificationStatus, level: string}
     */
    public function verify(string $partyId, string $kycReference, string $level = self::BASIC): array
    {
        if (trim($kycReference) === '') {
            throw new SafeDealException("Counterparty {$partyId} cannot be verified without a KYC reference.");
        }

        if (! in_array($level, [self::BASIC, self::ENHANCED], true)) {
            throw new SafeDealException("Unknown verification level '{$level}'.");
        }

        // The reference is evidence, not proof: it only counts when the
        // party's platform KYC is approved. A self-supplied string from a
        // user whose KYC was never approved (or was rejected/expired)
        // must never flip the party to VERIFIED.
        $user = User::find($partyId);

        if ($user === null || $user->kyc_status !== 'approved') {
            throw new SafeDealException(
                "Counterparty {$partyId} cannot be verified: platform KYC is not approved. " .
                'Complete platform KYC first; a self-supplied KYC reference is not a verification.'
            );
        }

        if ($level === self::ENHANCED && ! in_array($user->kyc_level, ['enhanced', 'full'], true)) {
            throw new SafeDealException(
                "Counterparty {$partyId} cannot be verified at level 'enhanced': " .
                "platform KYC level is '{$user->kyc_level}'. Complete enhanced platform KYC first."
            );
        }

        // No live provider call here: the reference is recorded as evidence
        // and the status is set by the explicit verification decision.
        return [
            'status'        => VerificationStatus::VERIFIED,
            'level'         => $level,
            'kyc_reference' => $kycReference,
            'verified_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * Fail-closed gate: both parties verified, and ENHANCED for both when
     * the amount reaches the threshold.
     *
     * @param array{buyer_verification: VerificationStatus, seller_verification: VerificationStatus, buyer_level: ?string, seller_level: ?string} $parties
     */
    public function passesGate(array $parties, string $amount): bool
    {
        if (
            $parties['buyer_verification'] !== VerificationStatus::VERIFIED
            || $parties['seller_verification'] !== VerificationStatus::VERIFIED
        ) {
            return false;
        }

        if (bccomp($amount, self::enhancedThreshold(), 4) >= 0) {
            return ($parties['buyer_level'] ?? null) === self::ENHANCED
                && ($parties['seller_level'] ?? null) === self::ENHANCED;
        }

        return true;
    }
}
