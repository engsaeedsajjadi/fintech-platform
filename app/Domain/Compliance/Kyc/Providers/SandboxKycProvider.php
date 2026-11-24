<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Providers;

use App\Domain\Compliance\Kyc\Contracts\IdentityVerificationProviderInterface;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Services\KycVerificationGate;

/**
 * Unmistakably-sandbox KYC provider.
 *
 * Implements the full IdentityVerificationProviderInterface so sandbox /
 * demo environments can exercise the whole verification workflow end to
 * end — but it NEVER returns a passing verdict:
 *
 *  - getHostedLink() points at an in-app sandbox page, never a vendor.
 *  - getStatus() is always 'pending'.
 *  - verifyLiveness() / verifyDocument() always return
 *    'requires_manual_review' with verified=false.
 *  - Webhooks are not accepted (validator always false).
 *
 * In production (or when simulated providers are disabled) every method
 * throws SimulatedVerificationNotAvailableException via the gate. A human
 * reviewer is the only path from a sandbox result to an approval, via
 * KycManualReviewService.
 */
final class SandboxKycProvider implements IdentityVerificationProviderInterface
{
    public function __construct(
        private readonly KycVerificationGate $gate = new KycVerificationGate(),
    ) {
    }

    public function getName(): string
    {
        return 'sandbox';
    }

    public function getHostedLink(int $userId, KycPurpose $purpose, array $context = []): string
    {
        $this->gate->assertSandboxAllowed('sandbox kyc hosted link');

        return rtrim((string) config('app.url'), '/')
            . '/sandbox/kyc/start?user_id=' . $userId
            . '&purpose=' . $purpose->value
            . '&provider=sandbox';
    }

    public function getStatus(int $userId): array
    {
        $this->gate->assertSandboxAllowed('sandbox kyc status');

        return [
            'status'   => 'pending',
            'metadata' => [
                'provider' => 'sandbox',
                'sandbox'  => true,
                'note'     => 'Sandbox provider: verification is never auto-approved; manual review required.',
            ],
        ];
    }

    public function getWebhookValidator(): callable
    {
        // The sandbox provider has no webhooks. Never accept one.
        return fn (string $rawBody, string $signatureHeader): bool => false;
    }

    public function getWebhookSignatureHeader(): string
    {
        return 'X-Sandbox-Signature';
    }

    public function normalizeWebhookPayload(array $payload): ?array
    {
        // Sandbox webhooks are ignored entirely.
        return null;
    }

    public function verifyLiveness(int $userId, array $evidence): array
    {
        $this->gate->assertSandboxAllowed('sandbox liveness');

        return $this->manualReviewResult('No real liveness provider is configured; sandbox liveness is not a verification.');
    }

    public function verifyDocument(int $userId, array $document): array
    {
        $this->gate->assertSandboxAllowed('sandbox document verification');

        return $this->manualReviewResult('No real document provider is configured; sandbox document checks are not a verification.');
    }

    /** @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>} */
    private function manualReviewResult(string $reason): array
    {
        return [
            'status'   => 'requires_manual_review',
            'verified' => false,
            'sandbox'  => true,
            'provider' => 'sandbox',
            'reason'   => $reason,
            'metadata' => [],
        ];
    }
}
