<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Providers;

use App\Domain\Compliance\Kyc\Contracts\BankAccountOwnershipVerifierInterface;
use App\Domain\Compliance\Services\KycVerificationGate;

/**
 * Sandbox bank-account ownership verifier.
 *
 * No real bank / PSP ownership check is contracted in-repo (no Iranian
 * bank API, no EU account-information provider for this purpose). This
 * adapter therefore always returns `requires_manual_review`: a human
 * reviewer must confirm ownership from bank evidence (e.g. a statement
 * showing the holder name). It never returns a passing verdict, in any
 * environment, and throws outside a sandbox environment.
 */
final class SandboxBankAccountOwnershipVerifier implements BankAccountOwnershipVerifierInterface
{
    public function __construct(
        private readonly KycVerificationGate $gate = new KycVerificationGate(),
    ) {
    }

    public function getName(): string
    {
        return 'sandbox';
    }

    /** @param  array<string, mixed>  $account */
    public function verify(int $userId, array $account): array
    {
        $this->gate->assertSandboxAllowed('sandbox bank-account ownership');

        return [
            'status'   => 'requires_manual_review',
            'verified' => false,
            'sandbox'  => true,
            'provider' => 'sandbox',
            'reason'   => 'No real bank-account ownership provider is configured; ownership must be confirmed by manual review of bank evidence.',
            'metadata' => [
                'account_last4' => $account['account_last4'] ?? null,
            ],
        ];
    }
}
