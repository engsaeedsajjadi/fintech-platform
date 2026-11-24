<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\ValueObjects;

use App\Domain\SafeDeal\Exceptions\SafeDealException;

/**
 * Money value object for SafeDeal trades.
 *
 * Amounts are decimal strings at the ledger posting scale (4). No float
 * arithmetic ever touches money: all math goes through bcmath.
 */
final readonly class TradeAmount
{
    public string $amount;

    public string $currency;

    public function __construct(
        string $amount,
        string $currency,
    ) {
        $normalisedCurrency = strtoupper($currency);

        if (! preg_match('/^[A-Z0-9]{2,10}$/', $normalisedCurrency)) {
            throw new SafeDealException("Invalid currency code '{$normalisedCurrency}'.");
        }

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new SafeDealException('Trade amount must be a positive decimal number.');
        }

        // Fail closed on precision: more than 4 decimals is refused, never truncated.
        if (str_contains($amount, '.') && strlen(substr($amount, (int) strpos($amount, '.') + 1)) > 4) {
            throw new SafeDealException('Trade amount exceeds the 4-decimal posting precision.');
        }

        // Promoted readonly properties may only be assigned once, from
        // local variables — never reassigned after promotion.
        $this->amount = bcadd($amount, '0', 4);
        $this->currency = $normalisedCurrency;
    }

    public static function fromMixed(mixed $amount, string $currency): self
    {
        if (is_float($amount) || is_int($amount)) {
            // Normalise through a string round-trip; floats are only accepted
            // at the API boundary and immediately converted to decimal strings.
            $amount = number_format((float) $amount, 4, '.', '');
        }

        if (! is_string($amount)) {
            throw new SafeDealException('Trade amount must be a decimal string or number.');
        }

        return new self($amount, $currency);
    }

    public function add(string $other): string
    {
        return bcadd($this->amount, $other, 4);
    }

    public function equals(string $other): bool
    {
        return bccomp($this->amount, $other, 4) === 0;
    }
}
