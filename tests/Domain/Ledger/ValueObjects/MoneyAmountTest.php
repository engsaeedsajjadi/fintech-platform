<?php

declare(strict_types=1);

use App\Domain\Ledger\ValueObjects\MoneyAmount;

it('converts integer minor units exactly', function (): void {
    expect(MoneyAmount::fromMinorUnits(1025, 'USD')->decimal)->toBe('10.25')
        ->and(MoneyAmount::fromMinorUnits(1000000, 'IRR')->decimal)->toBe('1000000')
        ->and(MoneyAmount::fromMinorUnits(1025, 'USD')->toMinorUnits())->toBe(1025);
});

it('adds and subtracts without float error', function (): void {
    // The classic 0.1 + 0.2 float case is exact in decimal strings.
    $sum = MoneyAmount::fromDecimalString('0.10', 'USD')->add(MoneyAmount::fromDecimalString('0.20', 'USD'));

    expect($sum->decimal)->toBe('0.3000')
        ->and($sum->subtract(MoneyAmount::fromDecimalString('0.10', 'USD'))->decimal)->toBe('0.2000');
});

it('rejects floats at the type level and currency mismatches', function (): void {
    // A float cannot even be passed: the signature only takes string|int.
    expect(fn () => MoneyAmount::of(10.5, 'USD'))->toThrow(TypeError::class);

    expect(fn () => MoneyAmount::fromDecimalString('not-a-number', 'USD'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => MoneyAmount::fromDecimalString('10.00', 'USD')->add(MoneyAmount::fromDecimalString('10.00', 'EUR')))
        ->toThrow(InvalidArgumentException::class, 'Currency mismatch');
});

it('reports zero, negative and positive correctly', function (): void {
    expect(MoneyAmount::fromDecimalString('0.0000', 'USD')->isZero())->toBeTrue()
        ->and(MoneyAmount::fromDecimalString('-1.0000', 'USD')->isNegative())->toBeTrue()
        ->and(MoneyAmount::fromDecimalString('0.0001', 'USD')->isPositive())->toBeTrue();
});
