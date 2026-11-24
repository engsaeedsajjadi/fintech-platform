<?php

declare(strict_types=1);

use App\Domain\Payment\Services\PaymentService;
use App\Domain\Payment\Services\TransferFeeCalculator;

it('refunds with integer minor units and returns exact amounts', function (): void {
    $result = (new PaymentService())->refundPayment('txn_1', 2550, 'USD', 'duplicate charge');

    expect($result['amount_minor'])->toBe(2550)
        ->and($result['currency'])->toBe('USD')
        ->and($result['status'])->toBe('processed')
        ->and(isset($result['amount']))->toBeFalse();
});

it('rejects non-positive refunds', function (): void {
    expect(fn () => (new PaymentService())->refundPayment('txn_1', 0, 'USD', 'zero'))
        ->toThrow(InvalidArgumentException::class);
});

it('verifies a payment with an exact zero minor-unit amount', function (): void {
    $result = (new PaymentService())->verifyPayment('txn_1');

    expect($result['amount_minor'])->toBe(0);
});

it('rejects float amounts at the payment boundary', function (): void {
    // A float amount cannot be passed: the amount must be an int of minor units.
    expect(fn () => (new PaymentService())->processPayment(['amount_minor' => 10.5, 'currency' => 'USD']))
        ->toThrow(InvalidArgumentException::class);

    $result = (new PaymentService())->processPayment(['amount_minor' => 1050, 'currency' => 'USD']);

    expect($result['amount_minor'])->toBe(1050);
});

it('calculates fees with exact decimal arithmetic', function (): void {
    $calculator = new TransferFeeCalculator();

    // 2.5% of 10000 minor + 50 fixed = 250 + 50 = 300; net 9700.
    $fee = $calculator->calculate(10000, 'USD', 250, 50);

    expect($fee['fee_minor'])->toBe(300)
        ->and($fee['fee_decimal'])->toBe('3.00')
        ->and($fee['net_minor'])->toBe(9700);

    // 0.1% of 333 minor truncates (never rounds up): 0 minor.
    $tiny = $calculator->calculate(333, 'USD', 10, 0);

    expect($tiny['fee_minor'])->toBe(0);

    // A fee equal to the amount is rejected.
    expect(fn () => $calculator->calculate(100, 'USD', 10000, 0))
        ->toThrow(InvalidArgumentException::class, 'Fee must be smaller');
});
