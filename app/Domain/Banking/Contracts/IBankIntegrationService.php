<?php

declare(strict_types=1);

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankTransfer;
use App\Models\User;
use Illuminate\Support\Collection;

interface IBankIntegrationService
{
    /**
     * Register a new bank connector.
     *
     * @param  string  $bankCode  Unique bank identifier
     * @param  IBankConnector  $connector  Bank connector implementation
     */
    public function registerConnector(string $bankCode, IBankConnector $connector): void;

    /**
     * Get a registered bank connector.
     *
     * @param  string  $bankCode  Bank identifier
     *
     * @throws \App\Domain\Banking\Exceptions\BankNotFoundException
     */
    public function getConnector(string $bankCode): IBankConnector;

    /**
     * Get all available bank connectors.
     *
     * @return Collection<string, IBankConnector>
     */
    public function getAvailableConnectors(): Collection;

    /**
     * Connect a user to a bank.
     *
     * @param  array  $credentials  Bank-specific credentials
     */
    public function connectUserToBank(User $user, string $bankCode, array $credentials): BankConnection;

    /**
     * Disconnect a user from a bank.
     */
    public function disconnectUserFromBank(User $user, string $bankCode): bool;

    /**
     * Get user's bank connections.
     *
     * @return Collection<BankConnection>
     */
    public function getUserBankConnections(User $user): Collection;

    /**
     * Create a bank account for a user.
     */
    public function createBankAccount(User $user, string $bankCode, array $accountDetails): BankAccount;

    /**
     * Get user's bank accounts.
     *
     * @param  string|null  $bankCode  Filter by bank
     * @return Collection<BankAccount>
     */
    public function getUserBankAccounts(User $user, ?string $bankCode = null): Collection;

    /**
     * Sync bank accounts for a user.
     *
     * @return Collection<BankAccount>
     */
    public function syncBankAccounts(User $user, string $bankCode): Collection;

    /**
     * Initiate a transfer between banks.
     *
     * Legacy float boundary — converted to an exact decimal string
     * immediately. New callers must use initiateInterBankTransferExact().
     */
    public function initiateInterBankTransfer(
        User $user,
        string $fromBankCode,
        string $fromAccountId,
        string $toBankCode,
        string $toAccountId,
        float $amount,
        string $currency,
        array $metadata = []
    ): BankTransfer;

    /**
     * Exact-amount inter-bank transfer. Provider calls run outside any
     * DB transaction; only the local transfer record is transactional.
     *
     * @param numeric-string $amount Exact decimal string in major units.
     * @param array<string, mixed> $metadata
     */
    public function initiateInterBankTransferExact(
        User $user,
        string $fromBankCode,
        string $fromAccountId,
        string $toBankCode,
        string $toAccountId,
        string $amount,
        string $currency,
        array $metadata = []
    ): BankTransfer;

    /**
     * Get optimal bank for a transaction.
     *
     * Legacy float boundary — converted to an exact decimal string
     * immediately. New callers must use getOptimalBankExact().
     *
     * @return string Bank code
     */
    public function getOptimalBank(User $user, string $currency, float $amount, string $transferType): string;

    /**
     * Exact-amount optimal-bank selection.
     *
     * @param numeric-string $amount Exact decimal string in major units.
     * @return string Bank code
     */
    public function getOptimalBankExact(User $user, string $currency, string $amount, string $transferType): string;

    /**
     * Check bank health status.
     *
     * @return array Health status details
     */
    public function checkBankHealth(string $bankCode): array;

    /**
     * Get aggregated balance across all banks.
     *
     * Legacy float API — lossy by nature. New callers must use
     * getAggregatedBalanceExact().
     */
    public function getAggregatedBalance(User $user, string $currency): float;

    /**
     * Exact aggregated balance across all banks, as a decimal string in
     * major units (bcmath sum, never a float artefact).
     */
    public function getAggregatedBalanceExact(User $user, string $currency): string;
}
