<?php

declare(strict_types=1);

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Models\FiatDeposit;
use App\Domain\Wallet\Models\WalletDepositWebhook;
use App\Domain\Wallet\Services\DepositWebhookService;
use App\Domain\Wallet\Services\FiatWalletService;
use App\Domain\Wallet\Services\WalletLimitsService;

beforeEach(function () {
    $this->service = new DepositWebhookService(new FiatWalletService(app(LedgerService::class), new WalletLimitsService()));
    $this->accountUuid = $this->account->uuid;
});

it('applies a deposit webhook once', function () {
    $first = $this->service->applyDepositWebhook('paysera', 'evt-1', $this->accountUuid, 'USD', '75.00', 'prov-ref-1', ['event' => 'deposit.completed']);

    expect($first['status'])->toBe('applied')
        ->and(FiatDeposit::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);

    $second = $this->service->applyDepositWebhook('paysera', 'evt-1', $this->accountUuid, 'USD', '75.00', 'prov-ref-1', ['event' => 'deposit.completed']);

    expect($second['status'])->toBe('duplicate')
        ->and($second['fiat_deposit_uuid'])->toBe($first['fiat_deposit_uuid'])
        ->and(FiatDeposit::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(WalletDepositWebhook::query()->where('provider', 'paysera')->where('provider_event_id', 'evt-1')->count())->toBe(1);
});

it('treats the same event id from a different provider as a different event', function () {
    $this->service->applyDepositWebhook('paysera', 'evt-1', $this->accountUuid, 'USD', '10.00', 'prov-ref-1');
    $other = $this->service->applyDepositWebhook('hyperswitch', 'evt-1', $this->accountUuid, 'USD', '10.00', 'prov-ref-2');

    expect($other['status'])->toBe('applied')->and(FiatDeposit::query()->count())->toBe(2);
});
