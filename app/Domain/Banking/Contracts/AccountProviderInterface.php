<?php

declare(strict_types=1);

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankCapabilities;

/**
 * Account provider — opening / closing EXTERNAL accounts held for users at
 * partner banks. Internal platform accounts are the Account domain's
 * aggregates and are NOT a provider concern.
 *
 * This is the account-opening seam from the target architecture (§4.2). The
 * only implementation today is the clearly-labelled SandboxBankAdapter; a
 * production implementation requires a partner bank's official API docs,
 * credentials and approval, none of which exist yet.
 */
interface AccountProviderInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    public function getCapabilities(): BankCapabilities;

    /**
     * Open an external account. The returned BankAccount (account number +
     * IBAN) comes from the provider — the caller must never invent one.
     *
     * @param  array<string, mixed>  $accountDetails  account_type, currency,
     *                                                holder_name, ...
     *
     * @throws \App\Domain\Banking\Exceptions\BankOperationException on a
     *         definitive provider rejection.
     */
    public function openAccount(string $userId, array $accountDetails, string $idempotencyKey): BankAccount;

    public function getAccount(string $externalAccountId): BankAccount;

    public function closeAccount(string $externalAccountId, string $reason, string $idempotencyKey): bool;

    public function freezeAccount(string $externalAccountId, string $reason): bool;

    public function unfreezeAccount(string $externalAccountId): bool;

    public function getBalance(string $externalAccountId, string $currency): BankBalance;

    /**
     * @return array{status: string, provider_reference: ?string}
     *         status: pending|approved|rejected|active|closed|unknown
     */
    public function getAccountStatus(string $externalAccountId): array;
}
