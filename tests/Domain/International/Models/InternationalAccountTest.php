<?php

declare(strict_types=1);

use App\Domain\International\Models\InternationalAccount;
use App\Domain\International\Services\InternationalAccountService;
use App\Domain\International\Services\InternationalAuditService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;

beforeEach(function (): void {
    $this->accounts = new InternationalAccountService(new InternationalAuditService());
    $this->ledger = new LedgerService(new EloquentDriver());
});

it('opens one account per currency with its own ledger account', function (): void {
    $usd = $this->accounts->openAccount($this->user, 'USD', 'Dollar account');
    $eur = $this->accounts->openAccount($this->user, 'EUR');

    expect($usd->currency)->toBe('USD')
        ->and($eur->currency)->toBe('EUR')
        ->and($usd->ledger_account_code)->not->toBe($eur->ledger_account_code)
        ->and(LedgerAccount::where('code', $usd->ledger_account_code)->exists())->toBeTrue()
        ->and($usd->isActive())->toBeTrue();

    expect($this->accounts->balance($usd))->toBe('0.0000');
});

it('refuses a second account in the same currency and unsupported currencies', function (): void {
    $this->accounts->openAccount($this->user, 'USD');

    expect(fn () => $this->accounts->openAccount($this->user, 'USD'))
        ->toThrow(InvalidArgumentException::class, 'already has');

    expect(fn () => $this->accounts->openAccount($this->user, 'XXX'))
        ->toThrow(InvalidArgumentException::class, 'not supported');
});

it('derives the balance from the ledger, never from a stored column', function (): void {
    $account = $this->accounts->openAccount($this->user, 'USD');

    LedgerAccount::firstOrCreate(
        ['code' => 'intl:funding:USD'],
        ['name' => 'Test funding', 'type' => 'asset', 'currency' => 'USD'],
    );

    $this->ledger->post('Fund 250', [
        ['account_code' => 'intl:funding:USD', 'debit' => '250.0000', 'credit' => '0.0000', 'currency' => 'USD'],
        ['account_code' => $account->ledger_account_code, 'debit' => '0.0000', 'credit' => '250.0000', 'currency' => 'USD'],
    ]);

    expect($this->accounts->balance($account->refresh()))->toBe('250.0000');

    // A direct column write cannot change the derived balance.
    InternationalAccount::where('id', $account->id)->update(['nickname' => 'renamed']);
    expect($this->accounts->balance($account->refresh()))->toBe('250.0000');
});
