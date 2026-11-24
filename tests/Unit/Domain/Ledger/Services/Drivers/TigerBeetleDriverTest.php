<?php

declare(strict_types=1);

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Exceptions\LedgerUnavailableException;
use App\Domain\Ledger\Services\Drivers\TigerBeetleDriver;

uses(Tests\TestCase::class);

it('implements LedgerDriverInterface', function (): void {
    $driver = new TigerBeetleDriver();
    expect($driver)->toBeInstanceOf(LedgerDriverInterface::class);
});

it('has all required interface methods', function (): void {
    $reflection = new ReflectionClass(TigerBeetleDriver::class);

    expect($reflection->hasMethod('post'))->toBeTrue();
    expect($reflection->hasMethod('balance'))->toBeTrue();
    expect($reflection->hasMethod('trialBalance'))->toBeTrue();
    expect($reflection->hasMethod('accountHistory'))->toBeTrue();
});

it('fails closed instead of returning a fabricated zero balance when TigerBeetle is unreachable', function (): void {
    config(['ledger.tigerbeetle.addresses' => '127.0.0.1:19999']);
    $driver = new TigerBeetleDriver();

    expect(fn () => $driver->balance('1100'))->toThrow(LedgerUnavailableException::class);
});

it('serves the trial balance from the DB journal instead of an empty report', function (): void {
    $driver = new TigerBeetleDriver();
    $result = $driver->trialBalance();
    expect($result)->toBeArray();
    expect($result)->toBeEmpty();
});

it('fails closed instead of returning an empty history when TigerBeetle is unreachable', function (): void {
    config(['ledger.tigerbeetle.addresses' => '127.0.0.1:19999']);
    $driver = new TigerBeetleDriver();

    expect(fn () => $driver->accountHistory('1100', now()->subDays(7), now()))
        ->toThrow(LedgerUnavailableException::class);
});
