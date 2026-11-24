<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Support\DecimalString;
use Carbon\Carbon;

class BankBalance
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        /** Exact decimal strings in major units — never floats. */
        public readonly string $available,
        public readonly string $current,
        public readonly string $pending,
        public readonly string $reserved,
        public readonly Carbon $asOf,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Get total balance (current + pending). Exact decimal string.
     *
     * @return string
     */
    public function getTotal(): string
    {
        return DecimalMath::add($this->current, $this->pending, 4);
    }

    /**
     * Get usable balance (available - reserved). Exact decimal string.
     *
     * @return string
     */
    public function getUsable(): string
    {
        return DecimalMath::sub($this->available, $this->reserved, 4);
    }

    /**
     * Check if balance is sufficient for an amount.
     *
     * @param numeric-string $amount Exact decimal string in major units.
     */
    public function hasSufficientFunds(string $amount): bool
    {
        return DecimalMath::cmp($this->getUsable(), $amount, 4) >= 0;
    }

    /**
     * Format balance for display.
     */
    public function format(): string
    {
        return sprintf(
            '%s %s (Available: %s)',
            $this->currency,
            $this->current,
            $this->available
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'currency'   => $this->currency,
            'available'  => $this->available,
            'current'    => $this->current,
            'pending'    => $this->pending,
            'reserved'   => $this->reserved,
            'as_of'      => $this->asOf->toIso8601String(),
            'metadata'   => $this->metadata,
        ];
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'],
            currency: $data['currency'],
            available: DecimalString::fromNumeric($data['available'] ?? 0, 4) ?? '0.0000',
            current: DecimalString::fromNumeric($data['current'] ?? 0, 4) ?? '0.0000',
            pending: DecimalString::fromNumeric($data['pending'] ?? 0, 4) ?? '0.0000',
            reserved: DecimalString::fromNumeric($data['reserved'] ?? 0, 4) ?? '0.0000',
            asOf: Carbon::parse($data['as_of']),
            metadata: $data['metadata'] ?? [],
        );
    }
}
