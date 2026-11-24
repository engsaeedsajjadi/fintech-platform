<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Aggregates\AssetTransactionAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\ValueObjects\CurrencyPrecisionRegistry;
use App\Domain\KeyManagement\Services\CustodialKeyGate;
use App\Domain\Wallet\Contracts\BlockchainProviderInterface;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\InvalidAssetException;
use App\Domain\Wallet\Exceptions\NetworkDisabledException;
use App\Domain\Wallet\Models\DigitalAssetAccount;
use App\Domain\Wallet\Models\DigitalAssetDeposit;
use App\Domain\Wallet\Models\DigitalAssetWithdrawal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Digital-asset wallet: asset account creation, deposit tracking with
 * on-chain confirmation tracking, withdrawal workflow (limits, approval,
 * confirmation tracking), tx-hash management, history, admin controls.
 *
 * Balances are the AccountBalance projection for (account_uuid,
 * asset_code). Balance changes go ONLY through
 * AssetTransactionAggregate::credit()/debit() — the projector moves the
 * balance — never through a direct field write. Deposits credit only
 * once confirmations reach the network requirement, and only once per
 * tx hash. Withdrawals debit only after approval, at broadcast time.
 *
 * NO private key is ever stored, logged or returned here. Custodial
 * accounts fail closed without a configured HSM/KMS (CustodialKeyGate);
 * only the HSM key reference is recorded. Signing itself is a
 * KeyManagement/HSM (or device) operation, not this service's.
 */
final class DigitalAssetWalletService
{
    public function __construct(
        private readonly WalletLimitsService $limits,
    ) {
    }

    /** Config-driven supported networks. @return array<string, array{confirmations: int, assets: array<int, string>}> */
    public function supportedNetworks(): array
    {
        return (array) config('wallet.digital.networks', []);
    }

    public function requiredConfirmations(string $network): int
    {
        return (int) ($this->supportedNetworks()[$network]['confirmations'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Asset accounts
    // ------------------------------------------------------------------

    public function createAssetAccount(
        string $accountUuid,
        string $assetCode,
        string $network,
        string $custodyType = DigitalAssetAccount::CUSTODY_NON_CUSTODIAL,
        ?string $address = null,
    ): DigitalAssetAccount {
        $this->validateNetworkAsset($network, $assetCode);
        if (! in_array($custodyType, [DigitalAssetAccount::CUSTODY_NON_CUSTODIAL, DigitalAssetAccount::CUSTODY_CUSTODIAL], true)) {
            throw new InvalidArgumentException("Unknown custody type '{$custodyType}'.");
        }

        $hsmKeyReference = null;
        if ($custodyType === DigitalAssetAccount::CUSTODY_CUSTODIAL) {
            // Fail closed: no HSM/KMS configured => no custodial account.
            $hsmKeyReference = CustodialKeyGate::requireHsm();
        }

        return DigitalAssetAccount::query()->firstOrCreate(
            ['account_uuid' => $accountUuid, 'asset_code' => strtoupper($assetCode), 'network' => strtolower($network)],
            [
                'uuid'              => (string) Str::uuid(),
                'address'           => $address,
                'custody_type'      => $custodyType,
                'hsm_key_reference' => $hsmKeyReference,
                'status'            => $address !== null ? DigitalAssetAccount::STATUS_ACTIVE : DigitalAssetAccount::STATUS_PENDING_ADDRESS,
            ],
        );
    }

    public function assignAddress(string $assetAccountUuid, string $address, BlockchainProviderInterface $provider): DigitalAssetAccount
    {
        $account = DigitalAssetAccount::query()->where('uuid', $assetAccountUuid)->firstOrFail();
        if (! $provider->validateAddress($address)) {
            throw new InvalidArgumentException("Address '{$address}' is not valid for this chain.");
        }
        $account->update(['address' => $address, 'status' => DigitalAssetAccount::STATUS_ACTIVE]);

        return $account->refresh();
    }

    /** Admin control: freeze / unfreeze / close an asset account. */
    public function setAccountStatus(string $assetAccountUuid, string $status): DigitalAssetAccount
    {
        if (! in_array($status, [DigitalAssetAccount::STATUS_ACTIVE, DigitalAssetAccount::STATUS_FROZEN, DigitalAssetAccount::STATUS_CLOSED, DigitalAssetAccount::STATUS_PENDING_ADDRESS], true)) {
            throw new InvalidArgumentException("Unknown asset account status '{$status}'.");
        }
        $account = DigitalAssetAccount::query()->where('uuid', $assetAccountUuid)->firstOrFail();
        $account->update(['status' => $status]);

        return $account->refresh();
    }

    // ------------------------------------------------------------------
    // Balances (read model only)
    // ------------------------------------------------------------------

    /** Balance in minor units (int). */
    public function balanceMinor(string $accountUuid, string $assetCode): int
    {
        return (int) (AccountBalance::query()
            ->where('account_uuid', $accountUuid)
            ->where('asset_code', strtoupper($assetCode))
            ->value('balance') ?? 0);
    }

    /** Balance in major units as an exact decimal string. */
    public function balance(string $accountUuid, string $assetCode): string
    {
        return DecimalMath::fromMinorUnits($this->balanceMinor($accountUuid, $assetCode), $this->precisionOf($assetCode));
    }

    // ------------------------------------------------------------------
    // Deposits (tx-hash + confirmation tracking)
    // ------------------------------------------------------------------

    /**
     * Record an observed on-chain deposit. Idempotent on (network, tx_hash):
     * a replay updates confirmations at most and never credits twice.
     * Credits via AssetTransactionAggregate once confirmations reach the
     * network requirement and the asset account is not frozen.
     */
    public function recordDeposit(
        string $accountUuid,
        string $assetCode,
        string $network,
        string $txHash,
        int $amountMinor,
        ?string $fromAddress = null,
        ?string $toAddress = null,
        int $confirmations = 0,
    ): DigitalAssetDeposit {
        $assetCode = strtoupper($assetCode);
        $network = strtolower($network);
        $this->validateNetworkAsset($network, $assetCode);
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Deposit amount must be positive (minor units).');
        }

        return DB::transaction(function () use ($accountUuid, $assetCode, $network, $txHash, $amountMinor, $fromAddress, $toAddress, $confirmations): DigitalAssetDeposit {
            $deposit = DigitalAssetDeposit::query()->where('network', $network)->where('tx_hash', $txHash)->lockForUpdate()->first();

            if ($deposit !== null) {
                if ($confirmations > $deposit->confirmations) {
                    $deposit->update(['confirmations' => $confirmations]);
                }
                $deposit = $deposit->refresh();
            } else {
                $deposit = DigitalAssetDeposit::query()->create([
                    'uuid'                   => (string) Str::uuid(),
                    'account_uuid'           => $accountUuid,
                    'asset_code'             => $assetCode,
                    'network'                => $network,
                    'tx_hash'                => $txHash,
                    'from_address'           => $fromAddress,
                    'to_address'             => $toAddress,
                    'amount_minor'           => $amountMinor,
                    'confirmations'          => $confirmations,
                    'required_confirmations' => $this->requiredConfirmations($network),
                    'status'                 => DigitalAssetDeposit::STATUS_PENDING,
                ]);
            }

            return $this->maybeCreditDeposit($deposit);
        });
    }

    /** Update confirmations from a chain provider; credits when the threshold is reached. */
    public function updateConfirmations(string $network, string $txHash, int $confirmations): ?DigitalAssetDeposit
    {
        return DB::transaction(function () use ($network, $txHash, $confirmations): ?DigitalAssetDeposit {
            $deposit = DigitalAssetDeposit::query()->where('network', strtolower($network))->where('tx_hash', $txHash)->lockForUpdate()->first();
            if ($deposit === null) {
                return null;
            }
            if ($confirmations > $deposit->confirmations) {
                $deposit->update(['confirmations' => $confirmations]);
                $deposit = $deposit->refresh();
            }

            return $this->maybeCreditDeposit($deposit);
        });
    }

    /** Poll a provider for one tx and apply its confirmation count. */
    public function trackConfirmations(string $network, string $txHash, BlockchainProviderInterface $provider): ?DigitalAssetDeposit
    {
        $status = $provider->getTransactionStatus($txHash);

        return $this->updateConfirmations($network, $txHash, (int) ($status['confirmations'] ?? 0));
    }

    // ------------------------------------------------------------------
    // Withdrawals (request -> approve/reject -> execute with tx hash -> confirm)
    // ------------------------------------------------------------------

    public function requestWithdrawal(
        string $accountUuid,
        string $assetCode,
        string $network,
        string $toAddress,
        int $amountMinor,
        string $idempotencyKey,
        ?string $requestedBy = null,
    ): DigitalAssetWithdrawal {
        $assetCode = strtoupper($assetCode);
        $network = strtolower($network);
        $this->validateNetworkAsset($network, $assetCode);
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be positive (minor units).');
        }
        if (trim($toAddress) === '') {
            throw new InvalidArgumentException('A destination address is required.');
        }

        return DB::transaction(function () use ($accountUuid, $assetCode, $network, $toAddress, $amountMinor, $idempotencyKey, $requestedBy): DigitalAssetWithdrawal {
            $existing = DigitalAssetWithdrawal::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->assertAssetAccountUsable($accountUuid, $assetCode, $network);
            $this->limits->checkDigitalWithdrawal($accountUuid, $assetCode, $amountMinor);
            $this->assertSufficientBalance($accountUuid, $assetCode, $amountMinor);

            $autoApproveUpTo = (int) config('wallet.digital.auto_approve_withdrawal_up_to_minor', 0);

            return DigitalAssetWithdrawal::query()->create([
                'uuid'         => (string) Str::uuid(),
                'account_uuid' => $accountUuid,
                'asset_code'   => $assetCode,
                'network'      => $network,
                'to_address'   => $toAddress,
                'amount_minor' => $amountMinor,
                'fee_minor'    => 0,
                'status'       => $autoApproveUpTo > 0 && $amountMinor <= $autoApproveUpTo
                    ? DigitalAssetWithdrawal::STATUS_APPROVED
                    : DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL,
                'idempotency_key'        => $idempotencyKey,
                'required_confirmations' => $this->requiredConfirmations($network),
                'requested_by'           => $requestedBy,
                'requested_at'           => Carbon::now(),
            ]);
        });
    }

    public function approveWithdrawal(string $withdrawalUuid, string $approver, ?string $note = null): DigitalAssetWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid, $approver, $note): DigitalAssetWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status !== DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status} and cannot be approved.");
            }
            if ($withdrawal->requested_by !== null && $withdrawal->requested_by === $approver) {
                throw new InvalidArgumentException('Four-eyes rule: the approver must differ from the requester.');
            }
            $withdrawal->update([
                'status'      => DigitalAssetWithdrawal::STATUS_APPROVED,
                'reviewed_by' => $approver,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    public function rejectWithdrawal(string $withdrawalUuid, string $reviewer, ?string $note = null): DigitalAssetWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid, $reviewer, $note): DigitalAssetWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status !== DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status} and cannot be rejected.");
            }
            $withdrawal->update([
                'status'      => DigitalAssetWithdrawal::STATUS_REJECTED,
                'reviewed_by' => $reviewer,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    /**
     * Execute an approved withdrawal at broadcast time. $txHash is the hash
     * returned by broadcasting the signed transaction via a
     * BlockchainProviderInterface (signing happens in KeyManagement / on
     * the device, never here). The internal debit is posted exactly once,
     * keyed by a deterministic aggregate uuid derived from the withdrawal.
     */
    public function executeWithdrawal(string $withdrawalUuid, string $txHash): DigitalAssetWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid, $txHash): DigitalAssetWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status === DigitalAssetWithdrawal::STATUS_BROADCAST || $withdrawal->status === DigitalAssetWithdrawal::STATUS_CONFIRMED) {
                return $withdrawal;
            }
            if ($withdrawal->status !== DigitalAssetWithdrawal::STATUS_APPROVED) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status}; only an approved withdrawal can execute.");
            }
            if (trim($txHash) === '') {
                throw new InvalidArgumentException('A broadcast tx hash is required to execute a withdrawal.');
            }

            $this->assertSufficientBalance($withdrawal->account_uuid, $withdrawal->asset_code, (int) $withdrawal->amount_minor);

            $aggregateUuid = $this->deterministicUuid("digital_withdrawal:{$withdrawal->uuid}");
            AssetTransactionAggregate::retrieve($aggregateUuid)
                ->debit(
                    accountUuid: AccountUuid::fromString($withdrawal->account_uuid),
                    assetCode: $withdrawal->asset_code,
                    money: new Money((int) $withdrawal->amount_minor),
                    description: "Digital asset withdrawal {$withdrawal->uuid} to {$withdrawal->to_address}",
                    metadata: ['withdrawal_uuid' => $withdrawal->uuid, 'tx_hash' => $txHash, 'network' => $withdrawal->network],
                )
                ->persist();

            $withdrawal->update([
                'status'               => DigitalAssetWithdrawal::STATUS_BROADCAST,
                'tx_hash'              => $txHash,
                'debit_aggregate_uuid' => $aggregateUuid,
                'broadcast_at'         => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    /** Update on-chain confirmations for a broadcast withdrawal. */
    public function trackWithdrawalConfirmations(string $withdrawalUuid, int $confirmations): DigitalAssetWithdrawal
    {
        $withdrawal = DigitalAssetWithdrawal::query()->where('uuid', $withdrawalUuid)->firstOrFail();
        if ($withdrawal->status !== DigitalAssetWithdrawal::STATUS_BROADCAST) {
            return $withdrawal;
        }
        $withdrawal->update(['confirmations' => max((int) $withdrawal->confirmations, $confirmations)]);
        if ($confirmations >= (int) $withdrawal->required_confirmations) {
            $withdrawal->update(['status' => DigitalAssetWithdrawal::STATUS_CONFIRMED]);
        }

        return $withdrawal->refresh();
    }

    /** Admin review hook: withdrawals waiting for a decision. */
    public function pendingWithdrawals(): Collection
    {
        return DigitalAssetWithdrawal::query()->where('status', DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL)->orderBy('created_at')->get();
    }

    /** Deposit + withdrawal history for one wallet account. */
    public function history(string $accountUuid, ?string $assetCode = null, int $limit = 100): array
    {
        $deposits = DigitalAssetDeposit::query()->where('account_uuid', $accountUuid)
            ->when($assetCode !== null, fn ($q) => $q->where('asset_code', strtoupper($assetCode)))
            ->latest()->limit($limit)->get()
            ->map(fn (DigitalAssetDeposit $d): array => ['type' => 'deposit', 'uuid' => $d->uuid, 'asset' => $d->asset_code, 'network' => $d->network, 'amount_minor' => (int) $d->amount_minor, 'tx_hash' => $d->tx_hash, 'confirmations' => (int) $d->confirmations, 'status' => $d->status, 'at' => $d->created_at?->toIso8601String()]);
        $withdrawals = DigitalAssetWithdrawal::query()->where('account_uuid', $accountUuid)
            ->when($assetCode !== null, fn ($q) => $q->where('asset_code', strtoupper($assetCode)))
            ->latest()->limit($limit)->get()
            ->map(fn (DigitalAssetWithdrawal $w): array => ['type' => 'withdrawal', 'uuid' => $w->uuid, 'asset' => $w->asset_code, 'network' => $w->network, 'amount_minor' => (int) $w->amount_minor, 'tx_hash' => $w->tx_hash, 'confirmations' => (int) $w->confirmations, 'status' => $w->status, 'at' => $w->created_at?->toIso8601String()]);

        return $deposits->merge($withdrawals)->sortByDesc('at')->values()->take($limit)->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function maybeCreditDeposit(DigitalAssetDeposit $deposit): DigitalAssetDeposit
    {
        if ($deposit->status === DigitalAssetDeposit::STATUS_CREDITED) {
            return $deposit;
        }
        if ((int) $deposit->confirmations < (int) $deposit->required_confirmations) {
            if ($deposit->status === DigitalAssetDeposit::STATUS_PENDING) {
                return $deposit;
            }
            $deposit->update(['status' => DigitalAssetDeposit::STATUS_PENDING]);

            return $deposit->refresh();
        }

        $assetAccount = DigitalAssetAccount::query()
            ->where('account_uuid', $deposit->account_uuid)
            ->where('asset_code', $deposit->asset_code)
            ->where('network', $deposit->network)
            ->first();
        if ($assetAccount !== null && $assetAccount->status === DigitalAssetAccount::STATUS_FROZEN) {
            // Chain fact recorded; credit deferred until an admin unfreezes.
            $deposit->update(['status' => DigitalAssetDeposit::STATUS_CONFIRMED]);

            return $deposit->refresh();
        }

        if (! DB::table('accounts')->where('uuid', $deposit->account_uuid)->exists()) {
            throw new RuntimeException("Account {$deposit->account_uuid} does not exist; cannot credit deposit {$deposit->uuid}.");
        }

        $aggregateUuid = $this->deterministicUuid("digital_deposit:{$deposit->network}:{$deposit->tx_hash}");
        AssetTransactionAggregate::retrieve($aggregateUuid)
            ->credit(
                accountUuid: AccountUuid::fromString($deposit->account_uuid),
                assetCode: $deposit->asset_code,
                money: new Money((int) $deposit->amount_minor),
                description: "Digital asset deposit {$deposit->tx_hash} on {$deposit->network}",
                metadata: ['deposit_uuid' => $deposit->uuid, 'tx_hash' => $deposit->tx_hash, 'network' => $deposit->network],
            )
            ->persist();

        $deposit->update([
            'status'                => DigitalAssetDeposit::STATUS_CREDITED,
            'credit_aggregate_uuid' => $aggregateUuid,
            'credited_at'           => Carbon::now(),
        ]);

        return $deposit->refresh();
    }

    private function validateNetworkAsset(string $network, string $assetCode): void
    {
        $networks = $this->supportedNetworks();
        if (! isset($networks[strtolower($network)])) {
            throw new NetworkDisabledException("Network '{$network}' is not supported. Supported: " . implode(', ', array_keys($networks)));
        }
        $assets = array_map('strval', (array) ($networks[strtolower($network)]['assets'] ?? []));
        if (! in_array(strtoupper($assetCode), array_map('strtoupper', $assets), true)) {
            throw new InvalidAssetException("Asset {$assetCode} is not supported on network {$network}. Supported: " . implode(', ', $assets));
        }
    }

    private function assertAssetAccountUsable(string $accountUuid, string $assetCode, string $network): void
    {
        $account = DigitalAssetAccount::query()
            ->where('account_uuid', $accountUuid)->where('asset_code', $assetCode)->where('network', $network)->first();
        if ($account === null) {
            return; // No asset account record yet: balance/limits still apply.
        }
        if ($account->status === DigitalAssetAccount::STATUS_FROZEN || $account->status === DigitalAssetAccount::STATUS_CLOSED) {
            throw new InvalidArgumentException("Asset account is {$account->status} and cannot withdraw.");
        }
    }

    private function assertSufficientBalance(string $accountUuid, string $assetCode, int $amountMinor): void
    {
        $available = $this->balanceMinor($accountUuid, $assetCode);
        if ($available < $amountMinor) {
            throw new InsufficientBalanceException($accountUuid, $assetCode, (string) $amountMinor, (string) $available);
        }
    }

    /**
     * Precision of an asset: the registered `assets` row first, then the
     * canonical currency registry (LedgerPrecision-backed). The old
     * hardcoded fallback of 8 mis-scaled every unregistered asset (e.g.
     * USDT at 8 instead of 6 — balances displayed 100x off).
     */
    private function precisionOf(string $assetCode): int
    {
        $row = Asset::query()->where('code', strtoupper($assetCode))->value('precision');
        if ($row !== null) {
            return (int) $row;
        }

        return CurrencyPrecisionRegistry::for($assetCode)->minorUnitExponent;
    }

    private function lockWithdrawal(string $withdrawalUuid): DigitalAssetWithdrawal
    {
        return DigitalAssetWithdrawal::query()->where('uuid', $withdrawalUuid)->lockForUpdate()->firstOrFail();
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = substr(hash('sha256', $seed), 0, 32);
        $hash[12] = '4';
        $hash[16] = dechex(0x8 | (hexdec($hash[16]) & 0x3));

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}
