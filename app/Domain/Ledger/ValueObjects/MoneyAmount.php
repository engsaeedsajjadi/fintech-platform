<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact decimal money. Amounts are carried as decimal strings (or integer
 * minor units) and all arithmetic goes through bcmath / Brick BigDecimal.
 *
 * Floats are NEVER accepted: a float constructor argument throws, because a
 * float has already lost the exact value by the time it reaches us. Callers
 * must pass a decimal string (e.g. '10.25'), an int of minor units, or an
 * int of major units. JSON numbers must be normalised with
 * App\Support\DecimalString::fromNumeric() at the boundary first.
 */
final readonly class MoneyAmount
{
    /** Decimal string in major units, at $scale places. */
    public string $decimal;

    public int $scale;

    public string $currency;

    private function __construct(string $decimal, int $scale, string $currency)
    {
        $this->decimal = $decimal;
        $this->scale = $scale;
        $this->currency = strtoupper($currency);
    }

    /**
     * @param string|int $amount Decimal string in major units, or int minor units when $isMinorUnits is true.
     */
    public static function of(string|int $amount, string $currency, int $scale = 4, bool $isMinorUnits = false): self
    {
        // The signature deliberately excludes float: a float has already
        // lost the exact value by the time it reaches us.
        if ($isMinorUnits) {
            $decimal = self::minorToDecimal((string) $amount, $scale, $currency);
        } else {
            $decimal = self::normalise((string) $amount, $scale);
        }

        return new self($decimal, $scale, $currency);
    }

    /**
     * Build from integer minor units (e.g. cents for USD, rials for IRR).
     * This is the preferred boundary form: an int cannot lose precision.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        $scale = self::minorUnitExponent($currency);

        return new self(self::minorToDecimal((string) $minorUnits, $scale, $currency), $scale, $currency);
    }

    /**
     * Build from a decimal string in major units, e.g. '10.25'.
     *
     * @throws InvalidArgumentException when the value is not a finite decimal.
     */
    public static function fromDecimalString(string $amount, string $currency, int $scale = 4): self
    {
        return new self(self::normalise($amount, $scale), $scale, $currency);
    }

    /**
     * Minor-unit exponent per ISO-4217 currency. IRR (rial) has 0 decimals
     * in practice for rail amounts; most currencies have 2; the ledger
     * posting scale (4) is a separate concern handled by the caller.
     */
    public static function minorUnitExponent(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW', 'IRR', 'VND', 'CLP'               => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            default                                         => 2,
        };
    }

    /** @return string */
    private static function normalise(string $amount, int $scale): string
    {
        $amount = trim($amount);

        if ($amount === '' || ! is_numeric($amount)) {
            throw new InvalidArgumentException("Invalid decimal amount: '{$amount}'.");
        }

        try {
            $normalised = (string) BigDecimal::of($amount)->toScale($scale, RoundingMode::Down);
        } catch (MathException $e) {
            throw new InvalidArgumentException("Invalid decimal amount: '{$amount}'.", 0, $e);
        }

        if (! is_numeric($normalised)) {
            throw new InvalidArgumentException("Invalid decimal amount: '{$amount}'.");
        }

        return $normalised;
    }

    /** @return string */
    private static function minorToDecimal(string $minorUnits, int $scale, string $currency): string
    {
        $minorUnits = trim($minorUnits);

        if (! preg_match('/^-?\d+$/', $minorUnits)) {
            throw new InvalidArgumentException("Minor units must be an integer string, got: '{$minorUnits}'.");
        }

        $exponent = self::minorUnitExponent($currency);
        $divisor = bcpow('10', (string) $exponent);

        // Convert minor units -> major units, then re-scale to $scale.
        $major = DecimalMath::div($minorUnits, $divisor, $scale + 2);

        return self::normalise($major, $scale);
    }

    /** Exact minor-unit value for this amount (truncates, never rounds up). */
    public function toMinorUnits(): int
    {
        $exponent = self::minorUnitExponent($this->currency);
        $minor = DecimalMath::mul($this->decimal, bcpow('10', (string) $exponent), 0);

        return (int) $minor;
    }

    public function isZero(): bool
    {
        return DecimalMath::cmp($this->decimal, '0', $this->scale) === 0;
    }

    public function isNegative(): bool
    {
        return DecimalMath::cmp($this->decimal, '0', $this->scale) < 0;
    }

    public function isPositive(): bool
    {
        return DecimalMath::cmp($this->decimal, '0', $this->scale) > 0;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(DecimalMath::add($this->decimal, $other->decimal, $this->scale), $this->scale, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(DecimalMath::sub($this->decimal, $other->decimal, $this->scale), $this->scale, $this->currency);
    }

    /** @return -1|0|1 */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return DecimalMath::cmp($this->decimal, $other->decimal, $this->scale);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
