<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Decimal fee calculation. fee = clamp(max(fixed, amount * bps / 10000),
 * min, max). All inputs/outputs are decimal strings; no float is used.
 */
final class FeeCalculator
{
    /**
     * @return numeric-string fee in the same units as $amount, at $scale
     */
    public static function calculate(string $amount, int $bps, string $fixed, string $min, string $max, int $scale = 4): string
    {
        $amountDecimal = BigDecimal::of($amount);
        $bpsFee = $amountDecimal->multipliedBy(BigDecimal::of($bps))
            ->dividedBy(BigDecimal::of(10000), $scale + 4, RoundingMode::HALF_UP);
        $fee = $bpsFee->compareTo(BigDecimal::of($fixed)) >= 0 ? $bpsFee : BigDecimal::of($fixed);

        if (BigDecimal::of($min)->isPositive() && $fee->compareTo(BigDecimal::of($min)) < 0) {
            $fee = BigDecimal::of($min);
        }

        if (BigDecimal::of($max)->isPositive() && $fee->compareTo(BigDecimal::of($max)) > 0) {
            $fee = BigDecimal::of($max);
        }

        // A fee can never exceed the amount itself.
        if ($fee->compareTo($amountDecimal) > 0) {
            $fee = $amountDecimal;
        }

        return (string) $fee->toScale($scale, RoundingMode::HALF_UP);
    }
}
