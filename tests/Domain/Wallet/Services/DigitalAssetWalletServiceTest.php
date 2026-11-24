<?php

declare(strict_types=1);

use App\Domain\Asset\Models\Asset;
use App\Domain\KeyManagement\Exceptions\HsmNotConfiguredException;
use App\Domain\Wallet\Exceptions\InvalidAssetException;
use App\Domain\Wallet\Exceptions\LimitExceededException;
use App\Domain\Wallet\Exceptions\NetworkDisabledException;
use App\Domain\Wallet\Models\DigitalAssetAccount;
use App\Domain\Wallet\Models\DigitalAssetDeposit;
use App\Domain\Wallet\Models\DigitalAssetWithdrawal;
use App\Domain\Wallet\Providers\SandboxBlockchainProvider;
use App\Domain\Wallet\Services\DigitalAssetWalletService;
use App\Domain\Wallet\Services\WalletLimitsService;

beforeEach(function () {
    $this->service = new DigitalAssetWalletService(new WalletLimitsService());
    $this->accountUuid = $this->account->uuid;
    Asset::query()->firstOrCreate(['code' => 'USDC'], ['name' => 'USD Coin', 'type' => 'crypto', 'precision' => 6, 'is_active' => true]);
    $this->provider = new SandboxBlockchainProvider('polygon');
});

describe('digital asset accounts', function () {
    it('creates a non-custodial asset account on a supported network', function () {
        $account = $this->service->createAssetAccount($this->accountUuid, 'USDC', 'polygon');

        expect($account->status)->toBe(DigitalAssetAccount::STATUS_PENDING_ADDRESS)
            ->and($account->custody_type)->toBe(DigitalAssetAccount::CUSTODY_NON_CUSTODIAL)
            ->and($account->hsm_key_reference)->toBeNull();
    });

    it('assigns an address from the blockchain provider', function () {
        $account = $this->service->createAssetAccount($this->accountUuid, 'USDC', 'polygon');
        $address = '0x' . str_repeat('a', 40);

        $assigned = $this->service->assignAddress($account->uuid, $address, $this->provider);

        expect($assigned->address)->toBe($address)->and($assigned->status)->toBe(DigitalAssetAccount::STATUS_ACTIVE);
    });

    it('rejects an unsupported network and an unsupported asset for the network', function () {
        expect(fn () => $this->service->createAssetAccount($this->accountUuid, 'USDC', 'dogecoin'))
            ->toThrow(NetworkDisabledException::class);
        expect(fn () => $this->service->createAssetAccount($this->accountUuid, 'BTC', 'polygon'))
            ->toThrow(InvalidAssetException::class);
    });

    it('fails closed for custodial accounts without an HSM', function () {
        expect(fn () => $this->service->createAssetAccount($this->accountUuid, 'USDC', 'polygon', DigitalAssetAccount::CUSTODY_CUSTODIAL))
            ->toThrow(HsmNotConfiguredException::class);
    });
});

describe('digital asset deposits', function () {
    it('credits the balance once confirmations reach the network requirement', function () {
        $this->service->createAssetAccount($this->accountUuid, 'USDC', 'polygon');

        $pending = $this->service->recordDeposit($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('b', 64), 1_000_000, null, null, 0);
        expect($pending->status)->toBe(DigitalAssetDeposit::STATUS_PENDING)
            ->and($this->service->balanceMinor($this->accountUuid, 'USDC'))->toBe(0);

        $credited = $this->service->updateConfirmations('polygon', '0x' . str_repeat('b', 64), 12);

        expect($credited->status)->toBe(DigitalAssetDeposit::STATUS_CREDITED)
            ->and($this->service->balanceMinor($this->accountUuid, 'USDC'))->toBe(1_000_000)
            ->and($this->service->balance($this->accountUuid, 'USDC'))->toBe('1.000000000000000000');
    });

    it('applies a duplicate deposit webhook / re-scan once', function () {
        $txHash = '0x' . str_repeat('c', 64);
        $first = $this->service->recordDeposit($this->accountUuid, 'USDC', 'polygon', $txHash, 2_000_000, null, null, 12);
        expect($first->status)->toBe(DigitalAssetDeposit::STATUS_CREDITED);

        // Replay with a different (conflicting) amount: same row, no second credit.
        $second = $this->service->recordDeposit($this->accountUuid, 'USDC', 'polygon', $txHash, 9_999_999, null, null, 12);

        expect($second->id)->toBe($first->id)
            ->and(DigitalAssetDeposit::query()->where('network', 'polygon')->where('tx_hash', $txHash)->count())->toBe(1)
            ->and($this->service->balanceMinor($this->accountUuid, 'USDC'))->toBe(2_000_000);
    });

    it('tracks confirmations through the blockchain provider seam', function () {
        $txHash = $this->provider->broadcastTransaction('0xsigned', 'dep-1');
        $this->provider->confirmTransaction($txHash, 12);
        $this->service->recordDeposit($this->accountUuid, 'USDC', 'polygon', $txHash, 500_000, null, null, 0);

        $tracked = $this->service->trackConfirmations('polygon', $txHash, $this->provider);

        expect($tracked->status)->toBe(DigitalAssetDeposit::STATUS_CREDITED)
            ->and((int) $tracked->confirmations)->toBe(12);
    });
});

describe('digital asset withdrawals', function () {
    beforeEach(function () {
        $this->service->createAssetAccount($this->accountUuid, 'USDC', 'polygon');
        $this->service->recordDeposit($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('d', 64), 5_000_000, null, null, 12);
    });

    it('runs approval then executes at broadcast with a tx hash', function () {
        $withdrawal = $this->service->requestWithdrawal($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('e', 40), 1_000_000, 'wd-1', 'user-a');
        expect($withdrawal->status)->toBe(DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL);

        expect(fn () => $this->service->approveWithdrawal($withdrawal->uuid, 'user-a'))
            ->toThrow(InvalidArgumentException::class, 'Four-eyes');

        $this->service->approveWithdrawal($withdrawal->uuid, 'admin-b');
        $executed = $this->service->executeWithdrawal($withdrawal->uuid, '0x' . str_repeat('f', 64));

        expect($executed->status)->toBe(DigitalAssetWithdrawal::STATUS_BROADCAST)
            ->and($this->service->balanceMinor($this->accountUuid, 'USDC'))->toBe(4_000_000);

        $confirmed = $this->service->trackWithdrawalConfirmations($withdrawal->uuid, 12);
        expect($confirmed->status)->toBe(DigitalAssetWithdrawal::STATUS_CONFIRMED);
    });

    it('rejects a withdrawal over the per-transaction limit', function () {
        config(['wallet.limits.digital.withdrawal_per_transaction_minor' => 100]);

        expect(fn () => $this->service->requestWithdrawal($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('e', 40), 1_000_000, 'wd-limit', 'user-a'))
            ->toThrow(LimitExceededException::class);
    });

    it('rejects a withdrawal above the balance and a frozen account withdrawal', function () {
        expect(fn () => $this->service->requestWithdrawal($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('e', 40), 99_000_000, 'wd-nofunds', 'user-a'))
            ->toThrow(App\Domain\Wallet\Exceptions\InsufficientBalanceException::class);

        $account = DigitalAssetAccount::query()->where('account_uuid', $this->accountUuid)->firstOrFail();
        $this->service->setAccountStatus($account->uuid, DigitalAssetAccount::STATUS_FROZEN);

        expect(fn () => $this->service->requestWithdrawal($this->accountUuid, 'USDC', 'polygon', '0x' . str_repeat('e', 40), 100, 'wd-frozen', 'user-a'))
            ->toThrow(InvalidArgumentException::class);
    });
});
