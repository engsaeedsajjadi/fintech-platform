<?php

declare(strict_types=1);

use App\Domain\International\Exceptions\ExchangeRateUnavailableException;
use App\Domain\International\Services\InternationalAuditService;
use App\Domain\International\Services\QuotationService;
use App\Domain\International\ValueObjects\ExchangeRate;

beforeEach(function (): void {
    $this->quotations = new QuotationService(new InternationalAuditService());
});

it('converts minor units exactly with truncation, never rounding up', function (): void {
    $rate = new ExchangeRate('USD', 'EUR', '0.9');

    expect($rate->convertMinor(10000))->toBe(9000); // 100.00 USD → 90.00 EUR
    expect($rate->convertMinor(10001))->toBe(9000); // 100.01 USD → 90.009 → truncated to 9000
    expect($rate->convertMinor(1))->toBe(0);
});

it('rejects non-positive and malformed rates', function (): void {
    expect(fn () => new ExchangeRate('USD', 'EUR', '0'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ExchangeRate('USD', 'EUR', '-1.5'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ExchangeRate('US', 'EUR', '0.9'))->toThrow(InvalidArgumentException::class);
});

it('creates a quotation with an operator-supplied rate and accepts it', function (): void {
    $quotation = $this->quotations->createQuotation($this->user, 'USD', 'EUR', 10000, '0.9');

    expect($quotation->status)->toBe('quoted')
        ->and($quotation->source)->toBe('manual')
        ->and((int) $quotation->destination_amount_minor)->toBe(9000);

    $accepted = $this->quotations->acceptQuotation($quotation, $this->user);

    expect($accepted->status)->toBe('accepted')
        ->and($accepted->isUsable())->toBeTrue();
});

it('refuses to accept an expired quotation', function (): void {
    $quotation = $this->quotations->createQuotation($this->user, 'USD', 'EUR', 10000, '0.9');
    $quotation->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $this->quotations->acceptQuotation($quotation->refresh(), $this->user))
        ->toThrow(ExchangeRateUnavailableException::class, 'expired');
});

it('refuses a quotation that does not match the transfer amount or pair', function (): void {
    $quotation = $this->quotations->createQuotation($this->user, 'USD', 'EUR', 10000, '0.9');
    $this->quotations->acceptQuotation($quotation, $this->user);

    expect(fn () => $this->quotations->usableQuotationFor($this->user, $quotation->id, 'USD', 'EUR', 9999))
        ->toThrow(ExchangeRateUnavailableException::class, 'does not match');

    expect(fn () => $this->quotations->usableQuotationFor($this->user, $quotation->id, 'USD', 'GBP', 10000))
        ->toThrow(ExchangeRateUnavailableException::class, 'does not match');
});

it('expires due quotations', function (): void {
    $quotation = $this->quotations->createQuotation($this->user, 'USD', 'EUR', 10000, '0.9');
    $quotation->update(['expires_at' => now()->subMinute()]);

    expect($this->quotations->expireDue())->toBe(1);
    expect($quotation->refresh()->status)->toBe('expired');
});
