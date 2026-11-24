<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Contracts\IBankConnector;
use App\Domain\Banking\Contracts\IBankIntegrationService;
use App\Domain\Banking\Exceptions\BankConnectionException;
use App\Domain\Banking\Exceptions\BankNotFoundException;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankAccountModel;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankConnectionModel;
use App\Domain\Banking\Models\BankTransfer;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Models\User;
use App\Support\DecimalString;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BankIntegrationService implements IBankIntegrationService
{
    /**
     * @var array<string, IBankConnector>
     */
    private array $connectors = [];

    private BankHealthMonitor $healthMonitor;

    private BankRoutingService $routingService;

    public function __construct(
        BankHealthMonitor $healthMonitor,
        BankRoutingService $routingService
    ) {
        $this->healthMonitor = $healthMonitor;
        $this->routingService = $routingService;
    }

    /**
     * {@inheritDoc}
     */
    public function registerConnector(string $bankCode, IBankConnector $connector): void
    {
        $this->connectors[$bankCode] = $connector;

        // Register with health monitor
        $this->healthMonitor->registerBank($bankCode, $connector);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnector(string $bankCode): IBankConnector
    {
        if (! isset($this->connectors[$bankCode])) {
            throw new BankNotFoundException("Bank connector not found: {$bankCode}");
        }

        return $this->connectors[$bankCode];
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableConnectors(): Collection
    {
        return collect($this->connectors)
            ->filter(fn ($connector) => $connector->isAvailable());
    }

    /**
     * {@inheritDoc}
     *
     * Network first, persistence second: the provider is authenticated
     * OUTSIDE any DB transaction (a network call inside a transaction
     * holds row locks while waiting on the network). The connection row
     * is then written in its own short transaction, and the initial
     * account sync runs AFTER the commit — it is network I/O and is
     * retryable on its own, so a sync failure is logged and the (real)
     * connection is kept instead of rolling it back.
     */
    public function connectUserToBank(User $user, string $bankCode, array $credentials): BankConnection
    {
        $connector = $this->getConnector($bankCode);

        // 1. Network: authenticate outside any transaction.
        try {
            $connector->authenticate();
        } catch (Exception $e) {
            Log::error(
                'Failed to connect user to bank',
                [
                    'user_id'   => $user->uuid,
                    'bank_code' => $bankCode,
                    'error'     => $e->getMessage(),
                ]
            );
            throw new BankConnectionException("Failed to connect to {$bankCode}: " . $e->getMessage(), previous: $e);
        }

        // 2. Local only: store the encrypted connection.
        $connectionModel = DB::transaction(
            fn () => BankConnectionModel::create(
                [
                    'id'           => Str::uuid()->toString(),
                    'user_uuid'    => $user->uuid,
                    'bank_code'    => $bankCode,
                    'status'       => 'active',
                    'credentials'  => encrypt($credentials),
                    'permissions'  => ['accounts', 'transactions', 'transfers'],
                    'last_sync_at' => null,
                    'expires_at'   => now()->addMonths(3),
                    'metadata'     => [
                        'bank_name'    => $connector->getBankName(),
                        'connected_at' => now()->toIso8601String(),
                    ],
                ]
            )
        );

        // 3. Network again, after the commit: initial account sync.
        try {
            $this->syncBankAccounts($user, $bankCode);
        } catch (Exception $e) {
            Log::warning(
                'Initial bank account sync failed after connect; connection kept, sync is retryable',
                [
                    'user_id'   => $user->uuid,
                    'bank_code' => $bankCode,
                    'error'     => $e->getMessage(),
                ]
            );
        }

        $fresh = $connectionModel->fresh();

        return BankConnection::fromArray(($fresh ?? $connectionModel)->toArray());
    }

    /**
     * {@inheritDoc}
     */
    public function disconnectUserFromBank(User $user, string $bankCode): bool
    {
        return DB::transaction(
            function () use ($user, $bankCode) {
                // Deactivate connection
                $updated = BankConnectionModel::where('user_uuid', $user->uuid)
                    ->where('bank_code', $bankCode)
                    ->where('status', 'active')
                    ->update(
                        [
                            'status'     => 'disconnected',
                            'updated_at' => now(),
                        ]
                    );

                // Deactivate associated accounts
                BankAccountModel::where('user_uuid', $user->uuid)
                    ->where('bank_code', $bankCode)
                    ->where('status', 'active')
                    ->update(
                        [
                            'status'     => 'disconnected',
                            'updated_at' => now(),
                        ]
                    );

                return $updated > 0;
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUserBankConnections(User $user): Collection
    {
        return BankConnectionModel::where('user_uuid', $user->uuid)
            ->where('status', 'active')
            ->get()
            ->map(fn ($model) => BankConnection::fromArray($model->toArray()));
    }

    /**
     * {@inheritDoc}
     *
     * The provider account is created FIRST (network, outside any
     * transaction); only the local row is written transactionally.
     * The account number / IBAN come from the provider response only.
     */
    public function createBankAccount(User $user, string $bankCode, array $accountDetails): BankAccount
    {
        $connector = $this->getConnector($bankCode);

        // Verify user has active connection (existence check only).
        BankConnectionModel::where('user_uuid', $user->uuid)
            ->where('bank_code', $bankCode)
            ->where('status', 'active')
            ->firstOrFail();

        // Network outside the transaction.
        $bankAccount = $connector->createAccount($accountDetails);

        // Local only, inside a short transaction.
        DB::transaction(
            function () use ($user, $bankCode, $bankAccount) {
                BankAccountModel::create(
                    [
                        'id'             => Str::uuid()->toString(),
                        'user_uuid'      => $user->uuid,
                        'bank_code'      => $bankCode,
                        'external_id'    => $bankAccount->id,
                        'account_number' => encrypt($bankAccount->accountNumber),
                        'iban'           => encrypt($bankAccount->iban),
                        'swift'          => $bankAccount->swift,
                        'currency'       => $bankAccount->currency,
                        'account_type'   => $bankAccount->accountType,
                        'status'         => 'active',
                        'metadata'       => $bankAccount->metadata,
                    ]
                );
            }
        );

        return $bankAccount;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserBankAccounts(User $user, ?string $bankCode = null): Collection
    {
        $query = BankAccountModel::where('user_uuid', $user->uuid)
            ->where('status', 'active');

        if ($bankCode) {
            $query->where('bank_code', $bankCode);
        }

        return $query->get()->map(
            function ($model) {
                return new BankAccount(
                    id: $model->external_id,
                    bankCode: $model->bank_code,
                    accountNumber: decrypt($model->account_number),
                    iban: decrypt($model->iban),
                    swift: (string) ($model->swift ?? ''),
                    currency: $model->currency,
                    accountType: $model->account_type,
                    status: $model->status,
                    holderName: ($model->metadata ?? [])['holder_name'] ?? null,
                    holderAddress: ($model->metadata ?? [])['holder_address'] ?? null,
                    metadata: $model->metadata ?? [],
                    createdAt: $model->created_at,
                    updatedAt: $model->updated_at,
                    closedAt: null
                );
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    public function syncBankAccounts(User $user, string $bankCode): Collection
    {
        $connector = $this->getConnector($bankCode);
        $connection = BankConnectionModel::where('user_uuid', $user->uuid)
            ->where('bank_code', $bankCode)
            ->where('status', 'active')
            ->firstOrFail();

        // Get credentials
        $credentials = decrypt($connection->credentials);
        $connector->authenticate();

        // Fetch accounts from bank via connector
        $accountIds = $this->getRemoteAccountIds($connector, $credentials);
        $accounts = collect();

        foreach ($accountIds as $accountId) {
            try {
                $accounts->push($connector->getAccount($accountId));
            } catch (Exception $e) {
                Log::warning('Failed to fetch account from bank', [
                    'bank_code'  => $bankCode,
                    'account_id' => $accountId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(
            function () use ($accounts, $user, $bankCode, $connection) {
                foreach ($accounts as $account) {
                    BankAccountModel::updateOrCreate(
                        [
                            'user_uuid'   => $user->uuid,
                            'bank_code'   => $bankCode,
                            'external_id' => $account->id,
                        ],
                        [
                            'account_number' => encrypt($account->accountNumber),
                            'iban'           => encrypt($account->iban),
                            'swift'          => $account->swift,
                            'currency'       => $account->currency,
                            'account_type'   => $account->accountType,
                            'status'         => $account->status,
                            'metadata'       => $account->metadata,
                            'updated_at'     => now(),
                        ]
                    );
                }

                // Update last sync time
                $connection->update(['last_sync_at' => now()]);
            }
        );

        return $accounts;
    }

    /**
     * Extract remote account IDs from connector credentials/metadata.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string>
     */
    private function getRemoteAccountIds(IBankConnector $connector, array $credentials): array
    {
        // Account IDs may be embedded in credentials during onboarding
        if (isset($credentials['account_ids']) && is_array($credentials['account_ids'])) {
            return $credentials['account_ids'];
        }

        // Fallback: use cached account list from the connector's configuration
        $cacheKey = "bank_accounts:{$connector->getBankCode()}";

        /** @var array<string> $accountIds */
        $accountIds = Cache::get($cacheKey, []);

        return $accountIds;
    }

    /**
     * {@inheritDoc}
     *
     * Legacy float boundary: the float is converted to an exact decimal
     * string immediately and the exact implementation runs. New callers
     * must use {@see initiateInterBankTransferExact()}.
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
    ): BankTransfer {
        $decimalAmount = DecimalString::fromNumeric($amount, 4) ?? '0.0000';

        return $this->initiateInterBankTransferExact(
            $user,
            $fromBankCode,
            $fromAccountId,
            $toBankCode,
            $toAccountId,
            $decimalAmount,
            $currency,
            $metadata,
        );
    }

    /**
     * Exact-amount inter-bank transfer.
     *
     * Order of operations (never a network call inside a DB transaction):
     *   1. local account lookup,
     *   2. provider balance check + provider transfer (network, outside
     *      any transaction; an unclear provider outcome is the
     *      connector's explicit failure/unknown, never a local guess),
     *   3. only the local transfer record is written transactionally.
     *
     * @param numeric-string $amount Exact decimal string in major units.
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
    ): BankTransfer {
        // Canonicalize the amount once, at the boundary: every downstream
        // consumer (provider, local record, returned transfer) sees the
        // same exact 4-decimal string, never '10.10' vs '10.1000' drift.
        $amount = DecimalString::fromNumeric($amount, 4)
            ?? throw new BankConnectionException("Invalid transfer amount: {$amount}");

        // Both connectors must exist before any money moves.
        $fromConnector = $this->getConnector($fromBankCode);
        $this->getConnector($toBankCode);

        // Verify accounts belong to user (existence check only).
        BankAccountModel::where('user_uuid', $user->uuid)
            ->where('bank_code', $fromBankCode)
            ->where('external_id', $fromAccountId)
            ->where('status', 'active')
            ->firstOrFail();

        // Network, outside any transaction.
        $balance = $fromConnector->getBalance($fromAccountId, $currency);
        if (! $balance instanceof BankBalance) {
            throw new BankConnectionException("Balance check for {$fromAccountId} did not return a single balance.");
        }
        if (! $balance->hasSufficientFunds($amount)) {
            throw new Exception('Insufficient funds');
        }

        // Determine transfer type
        $transferType = $this->routingService->determineTransferType(
            $fromBankCode,
            $toBankCode,
            $currency,
            $amount
        );

        // Initiate transfer with the provider.
        $transfer = $fromConnector->initiateTransfer(
            [
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'to_bank_code'    => $toBankCode,
                'amount'          => $amount,
                'currency'        => $currency,
                'type'            => $transferType,
                'reference'       => $metadata['reference'] ?? Str::random(16),
                'description'     => $metadata['description'] ?? null,
            ]
        );

        // The provider echoes its own amount spelling; normalize the
        // returned transfer to the canonical amount so callers never see
        // a different representation of the same value.
        if ($transfer->amount !== $amount) {
            $transfer = new BankTransfer(
                id: $transfer->id,
                bankCode: $transfer->bankCode,
                type: $transfer->type,
                status: $transfer->status,
                fromAccountId: $transfer->fromAccountId,
                toAccountId: $transfer->toAccountId,
                toBankCode: $transfer->toBankCode,
                amount: $amount,
                currency: $transfer->currency,
                reference: $transfer->reference,
                description: $transfer->description,
                fees: $transfer->fees,
                exchangeRate: $transfer->exchangeRate,
                createdAt: $transfer->createdAt,
                updatedAt: $transfer->updatedAt,
                executedAt: $transfer->executedAt,
                failedAt: $transfer->failedAt,
                failureReason: $transfer->failureReason,
                metadata: $transfer->metadata,
            );
        }

        // Local record only, inside a short transaction.
        DB::transaction(
            function () use ($user, $transfer, $fromBankCode, $fromAccountId, $toBankCode, $toAccountId, $amount, $currency, $transferType) {
                DB::table('bank_transfers')->insert(
                    [
                        'id'              => $transfer->id,
                        'user_uuid'       => $user->uuid,
                        'from_bank_code'  => $fromBankCode,
                        'from_account_id' => $fromAccountId,
                        'to_bank_code'    => $toBankCode,
                        'to_account_id'   => $toAccountId,
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'type'            => $transferType,
                        'status'          => $transfer->status,
                        'metadata'        => json_encode($transfer->metadata),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]
                );
            }
        );

        return $transfer;
    }

    /**
     * {@inheritDoc}
     *
     * Legacy float API for existing callers (GraphQL/REST). The float is
     * lossy by nature — new callers must use
     * {@see getAggregatedBalanceExact()}.
     */
    public function getOptimalBank(User $user, string $currency, float $amount, string $transferType): string
    {
        $decimalAmount = DecimalString::fromNumeric($amount, 4) ?? '0.0000';

        return $this->getOptimalBankExact($user, $currency, $decimalAmount, $transferType);
    }

    /**
     * Exact-amount optimal-bank selection.
     *
     * @param numeric-string $amount Exact decimal string in major units.
     */
    public function getOptimalBankExact(User $user, string $currency, string $amount, string $transferType): string
    {
        return $this->routingService->getOptimalBank(
            $user,
            $currency,
            $amount,
            $transferType,
            $this->getUserBankConnections($user)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function checkBankHealth(string $bankCode): array
    {
        return $this->healthMonitor->checkHealth($bankCode);
    }

    /**
     * {@inheritDoc}
     *
     * Legacy float API for existing callers (GraphQL/REST). The float is
     * lossy by nature — new callers must use
     * {@see getAggregatedBalanceExact()}.
     */
    public function getAggregatedBalance(User $user, string $currency): float
    {
        return (float) $this->getAggregatedBalanceExact($user, $currency);
    }

    /**
     * Exact aggregated balance across all of the user's banks, as a
     * decimal string in major units. Balances are summed with bcmath —
     * 0.10 + 0.20 is 0.3000, never a float artefact.
     */
    public function getAggregatedBalanceExact(User $user, string $currency): string
    {
        $cacheKey = "user_balance:{$user->uuid}:{$currency}";

        $total = Cache::remember(
            $cacheKey,
            300,
            function () use ($user, $currency) {
                $totalBalance = '0.0000';

                $connections = $this->getUserBankConnections($user);

                foreach ($connections as $connection) {
                    if (! $connection->isActive()) {
                        continue;
                    }

                    try {
                        $connector = $this->getConnector($connection->bankCode);
                        $accounts = $this->getUserBankAccounts($user, $connection->bankCode);

                        foreach ($accounts as $account) {
                            if ($account->supportsCurrency($currency)) {
                                $balance = $connector->getBalance($account->id, $currency);
                                if ($balance instanceof BankBalance) {
                                    $totalBalance = DecimalMath::add($totalBalance, $balance->available, 4);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        Log::warning(
                            'Failed to get balance from bank',
                            [
                                'bank_code' => $connection->bankCode,
                                'error'     => $e->getMessage(),
                            ]
                        );
                    }
                }

                return $totalBalance;
            }
        );

        return (string) $total;
    }
}
