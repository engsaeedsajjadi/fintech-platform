<?php

declare(strict_types=1);

namespace Tests\Domain\Banking\Models;

use App\Domain\Banking\Models\BankBalance;
use Carbon\Carbon;
use Tests\UnitTestCase;

// Amounts on BankBalance are exact decimal strings in major units — never
// floats. Expectations below assert the exact strings.
class BankBalanceTest extends UnitTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_total_as_current_plus_pending(): void
    {
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '800.0000',
            pending: '200.0000',
            reserved: '50.0000',
            asOf: Carbon::now(),
        );

        expect($balance->getTotal())->toBe('1000.0000');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_usable_as_available_minus_reserved(): void
    {
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '800.0000',
            pending: '200.0000',
            reserved: '50.0000',
            asOf: Carbon::now(),
        );

        expect($balance->getUsable())->toBe('950.0000');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_true_when_sufficient_funds(): void
    {
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '1000.0000',
            pending: '0.0000',
            reserved: '100.0000',
            asOf: Carbon::now(),
        );

        // Usable = 1000 - 100 = 900
        expect($balance->hasSufficientFunds('900.0000'))->toBeTrue();
        expect($balance->hasSufficientFunds('500.0000'))->toBeTrue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_false_when_insufficient_funds(): void
    {
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '1000.0000',
            pending: '0.0000',
            reserved: '100.0000',
            asOf: Carbon::now(),
        );

        // Usable = 1000 - 100 = 900
        expect($balance->hasSufficientFunds('901.0000'))->toBeFalse();
        expect($balance->hasSufficientFunds('1000.0000'))->toBeFalse();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_formats_balance_correctly(): void
    {
        // Amounts are exact decimal strings in major units.
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.50',
            current: '850.00',
            pending: '0.0000',
            reserved: '0.0000',
            asOf: Carbon::now(),
        );

        $formatted = $balance->format();

        expect($formatted)->toContain('EUR');
        expect($formatted)->toContain('850.00');
        expect($formatted)->toContain('1000.50');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_converts_to_array(): void
    {
        $now = Carbon::now();
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '800.0000',
            pending: '200.0000',
            reserved: '50.0000',
            asOf: $now,
            metadata: ['source' => 'sync'],
        );

        $array = $balance->toArray();

        expect($array['account_id'])->toBe('ACC001');
        expect($array['currency'])->toBe('EUR');
        expect($array['available'])->toBe('1000.0000');
        expect($array['current'])->toBe('800.0000');
        expect($array['pending'])->toBe('200.0000');
        expect($array['reserved'])->toBe('50.0000');
        expect($array['as_of'])->toBe($now->toIso8601String());
        expect($array['metadata'])->toBe(['source' => 'sync']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_from_array(): void
    {
        $data = [
            'account_id' => 'ACC001',
            'currency'   => 'EUR',
            'available'  => 1000.0,
            'current'    => 800.0,
            'pending'    => 200.0,
            'reserved'   => 50.0,
            'as_of'      => '2024-01-15T10:00:00+00:00',
            'metadata'   => ['source' => 'api'],
        ];

        $balance = BankBalance::fromArray($data);

        expect($balance->accountId)->toBe('ACC001');
        expect($balance->currency)->toBe('EUR');
        expect($balance->available)->toBe('1000.0000');
        expect($balance->current)->toBe('800.0000');
        expect($balance->pending)->toBe('200.0000');
        expect($balance->reserved)->toBe('50.0000');
        expect($balance->metadata)->toBe(['source' => 'api']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_zero_reserved_in_usable_calculation(): void
    {
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '1000.0000',
            current: '1000.0000',
            pending: '0.0000',
            reserved: '0.0000',
            asOf: Carbon::now(),
        );

        expect($balance->getUsable())->toBe('1000.0000');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_negative_pending(): void
    {
        // Pending can be negative (e.g., pending outgoing transfers)
        $balance = new BankBalance(
            accountId: 'ACC001',
            currency: 'EUR',
            available: '800.0000',
            current: '1000.0000',
            pending: '-200.0000',
            reserved: '0.0000',
            asOf: Carbon::now(),
        );

        // Total = current + pending = 1000 + (-200) = 800
        expect($balance->getTotal())->toBe('800.0000');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_from_array_with_default_metadata(): void
    {
        $data = [
            'account_id' => 'ACC001',
            'currency'   => 'EUR',
            'available'  => 1000.0,
            'current'    => 1000.0,
            'pending'    => 0.0,
            'reserved'   => 0.0,
            'as_of'      => '2024-01-15T10:00:00+00:00',
            // No metadata key
        ];

        $balance = BankBalance::fromArray($data);

        expect($balance->metadata)->toBe([]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_keeps_string_amounts_exact(): void
    {
        $data = [
            'account_id' => 'ACC001',
            'currency'   => 'EUR',
            'available'  => '1000.50', // String
            'current'    => '800.25',
            'pending'    => '200.25',
            'reserved'   => '50.00',
            'as_of'      => '2024-01-15T10:00:00+00:00',
        ];

        $balance = BankBalance::fromArray($data);

        expect($balance->available)->toBe('1000.5000');
        expect($balance->current)->toBe('800.2500');
        expect($balance->pending)->toBe('200.2500');
        expect($balance->reserved)->toBe('50.0000');
    }
}
