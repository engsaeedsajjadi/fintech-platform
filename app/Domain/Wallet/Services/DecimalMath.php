<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact decimal arithmetic for wallet money. Amounts are decimal strings
 * (never float); fiat is carried at scale 4 (the ledger's scale),
 * conversion rates at scale 10, crypto major amounts at scale 18.
 */
final class DecimalMath
{
    public static function assertPositiveDecimal(string $amount, string $field = 'amount'): void
    {
        if (! is_numeric($amount) || preg_match('/^\d+(\.\d+)?$/', $amount) !== 1 || BigDecimal::of($amount)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException("{$field} must be a positive decimal string, got '{$amount}'.");
        }
    }

    public static function assertNonNegativeDecimal(string $amount, string $field = 'amount'): void
    {
        if (! is_numeric($amount) || preg_match('/^\d+(\.\d+)?$/', $amount) !== 1 || BigDecimal::of($amount)->isNegative()) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal string, got '{$amount}'.");
        }
    }

    /** @return numeric-string */
    public static function scale4(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HALF_UP);
    }

    /** @return numeric-string */
    public static function scale18(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(18, RoundingMode::DOWN);
    }

    /** @return numeric-string */
    public static function add(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->plus(BigDecimal::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    /** @return numeric-string */
    public static function sub(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->minus(BigDecimal::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    /** @return numeric-string */
    public static function mul(string $a, string $b, int $scale = 18): string
    {
        return (string) BigDecimal::of($a)->multipliedBy(BigDecimal::of($b))->toScale($scale, RoundingMode::DOWN);
    }

    public static function cmp(string $a, string $b): int
    {
        return BigDecimal::of($a)->compareTo(BigDecimal::of($b));
    }

    public static function isPositive(string $amount): bool
    {
        return is_numeric($amount) && BigDecimal::of($amount)->isPositive();
    }

    /**
     * Convert a float rate from a rate provider into an exact decimal
     * string. The float is a provider boundary value only; from here on
     * the string is the source of truth. Throws when the float is not a
     * finite positive number — a bad rate is never silently used.
     *
     * @return numeric-string
     */
    public static function rateFromFloat(float $rate): string
    {
        if (! is_finite($rate) || $rate <= 0) {
            throw new InvalidArgumentException("Exchange rate must be a finite positive number, got '{$rate}'.");
        }

        return sprintf('%.10F', $rate);
    }

    /** Convert a major-unit decimal amount to minor units for an asset. */
    public static function toMinorUnits(string $majorAmount, int $precision): int
    {
        $minor = BigDecimal::of($majorAmount)
            ->multipliedBy(BigDecimal::of(10)->power($precision))
            ->toScale(0, RoundingMode::DOWN);

        return (int) (string) $minor;
    }

    /** Convert minor units back to a major-unit decimal string. */
    public static function fromMinorUnits(int $minorAmount, int $precision): string
    {
        return (string) BigDecimal::of($minorAmount)
            ->dividedBy(BigDecimal::of(10)->power($precision), 18, RoundingMode::DOWN);
    }
}
