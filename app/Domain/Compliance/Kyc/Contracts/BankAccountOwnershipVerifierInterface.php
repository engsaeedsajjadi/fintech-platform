<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Contracts;

/**
 * Bank-account ownership verification: proves the named bank account
 * belongs to the user (e.g. a micro-deposit / penny-drop, or a bank's own
 * account-holder confirmation).
 *
 * No real provider is contracted in-repo. The only implementation today is
 * the sandbox adapter, which always returns `requires_manual_review`.
 * A future real adapter (Iranian bank / PSP, or a licensed EU provider)
 * implements this same interface; callers never change.
 *
 * @return array{status: 'approved'|'rejected'|'pending'|'requires_manual_review'|'not_verified', verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
 */
interface BankAccountOwnershipVerifierInterface
{
    public function getName(): string;

    /** @param  array<string, mixed>  $account  Account references only (holder name, last-4, hash). Never full account numbers. */
    public function verify(int $userId, array $account): array;
}
