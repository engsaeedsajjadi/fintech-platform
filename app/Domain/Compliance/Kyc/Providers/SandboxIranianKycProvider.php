<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Providers;

use App\Domain\Compliance\Kyc\Contracts\IranianKycProviderInterface;
use App\Domain\Compliance\Services\KycVerificationGate;

/**
 * Sandbox-only Iranian KYC provider.
 *
 * Implements the full IranianKycProviderInterface so the Iranian
 * onboarding flow can be exercised end to end in sandbox / demo
 * environments — but it NEVER returns a passing verdict:
 *
 *  - validateNationalId() runs the real Iranian national-code
 *    checksum (10 digits, not all identical, check digit valid). That
 *    is pure arithmetic on the supplied string — no registry, no
 *    network, no government database. A passing checksum only proves
 *    the number is well-formed; the result still carries
 *    `verified=false` and status `requires_manual_review`.
 *  - verifyNationalIdentity() / verifyMobileOwnership() /
 *    verifyBankAccountOwnership() / verifyDocument() always return
 *    `requires_manual_review` with verified=false: no Shahkar lookup,
 *    no civil-registry lookup, no bank ownership API exists in-repo.
 *  - requestHumanReview() returns a queueing shape; the caller still
 *    has to create the KycManualReview row via KycManualReviewService
 *    — the adapter itself approves nothing.
 *
 * In production (or when simulated providers are disabled) every
 * method throws SimulatedVerificationNotAvailableException via the
 * gate. A human reviewer is the only path from a sandbox result to
 * an approval, via KycManualReviewService.
 */
final class SandboxIranianKycProvider implements IranianKycProviderInterface
{
    public function __construct(
        private readonly KycVerificationGate $gate = new KycVerificationGate(),
    ) {
    }

    public function getName(): string
    {
        return 'iranian_sandbox';
    }

    /** @return array{format_valid: bool, verified: bool, sandbox: bool, provider: string, status: string, reason: ?string, metadata: array<string, mixed>} */
    public function validateNationalId(string $nationalCode): array
    {
        $this->gate->assertSandboxAllowed('iranian national-id validation');

        $formatValid = self::isValidNationalCodeFormat($nationalCode);

        return [
            'format_valid' => $formatValid,
            // Format validity is NOT identity proof: a well-formed number
            // says nothing about who it belongs to. Never verified here.
            'verified' => false,
            'sandbox'  => true,
            'provider' => 'iranian_sandbox',
            'status'   => 'requires_manual_review',
            'reason'   => $formatValid
                ? 'National code is well-formed (checksum valid); identity still requires a real registry check or human review.'
                : 'National code is not well-formed (must be 10 digits with a valid checksum).',
            'metadata' => [
                'last4' => strlen($nationalCode) >= 4 ? substr($nationalCode, -4) : null,
            ],
        ];
    }

    /**
     * /** @param  array<string, mixed>  $identity
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyNationalIdentity(int $userId, array $identity): array
    {
        $this->gate->assertSandboxAllowed('iranian national identity verification');

        return $this->manualReviewResult(
            'No Iranian civil-registry integration is contracted; national identity must be confirmed by human review of the document.'
        );
    }

    /**
     * /** @param  array<string, mixed>  $mobile
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyMobileOwnership(int $userId, array $mobile): array
    {
        $this->gate->assertSandboxAllowed('iranian mobile ownership verification');

        return $this->manualReviewResult(
            'No Shahkar / operator ownership check is contracted; mobile ownership must be confirmed by human review (e.g. an operator OTP the reviewer can see).'
        );
    }

    /**
     * /** @param  array<string, mixed>  $account
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyBankAccountOwnership(int $userId, array $account): array
    {
        $this->gate->assertSandboxAllowed('iranian bank-account ownership verification');

        return $this->manualReviewResult(
            'No Iranian bank / PSP ownership API is contracted; ownership must be confirmed by manual review of bank evidence.'
        );
    }

    /**
     * /**
     * /** @param  array<string, mixed>  $document
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyDocument(int $userId, array $document): array
    {
        $this->gate->assertSandboxAllowed('iranian document verification');

        return $this->manualReviewResult(
            'No Iranian document verification provider is contracted; the document must be inspected by a human reviewer.'
        );
    }

    public function requestHumanReview(int $userId, string $reason): array
    {
        $this->gate->assertSandboxAllowed('iranian human review request');

        return [
            'status'                  => 'requires_manual_review',
            'verified'                => false,
            'sandbox'                 => true,
            'provider'                => 'iranian_sandbox',
            'reason'                  => $reason,
            'queued_for_human_review' => true,
            'metadata'                => [
                // The caller creates the KycManualReview row via
                // KycManualReviewService::submitForReview(); this adapter
                // only reports that the case belongs in that queue.
                'note' => 'Create the review via KycManualReviewService::submitForReview(); this result alone queues nothing.',
            ],
        ];
    }

    /**
     * Iranian national-code (کد ملی) format check: exactly 10 digits,
     * not all identical, and the check digit satisfies
     * sum(d[i] * (10 - i), i = 0..8) mod 11 (remainder < 2 => check
     * digit == remainder, else check digit == 11 - remainder).
     * Pure arithmetic — no registry lookup of any kind.
     */
    public static function isValidNationalCodeFormat(string $nationalCode): bool
    {
        if (! preg_match('/^\d{10}$/', $nationalCode)) {
            return false;
        }

        if (preg_match('/^(\d)\1{9}$/', $nationalCode)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $nationalCode[$i]) * (10 - $i);
        }

        $remainder = $sum % 11;
        $checkDigit = (int) $nationalCode[9];

        return $remainder < 2 ? $checkDigit === $remainder : $checkDigit === 11 - $remainder;
    }

    /** @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>} */
    private function manualReviewResult(string $reason): array
    {
        return [
            'status'   => 'requires_manual_review',
            'verified' => false,
            'sandbox'  => true,
            'provider' => 'iranian_sandbox',
            'reason'   => $reason,
            'metadata' => [],
        ];
    }
}
