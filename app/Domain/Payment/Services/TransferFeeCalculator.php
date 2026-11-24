<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\ValueObjects\MoneyAmount;
use InvalidArgumentException;

/**
 * Exact decimal fee calculation for internal transfers.
 *
 * fee = amount * basis_points / 10_000 + fixed_minor, all in integer minor
 * units. The percentage part is computed with Brick BigDecimal from the
 * decimal-string amount and truncated (rounded DOWN) so the fee can never
 * exceed the configured rate. No float arithmetic anywhere.
 */
final class TransferFeeCalculator
{
    /**
     * @return array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int}
     */
    public function calculate(int $amountMinor, string $currency, int $basisPoints = 0, int $fixedMinor = 0): array
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Fee calculation needs a positive amount in minor units.');
        }

        if ($basisPoints < 0 || $fixedMinor < 0) {
            throw new InvalidArgumentException('Fee rate and fixed fee must not be negative.');
        }

        // Percentage fee in minor units, truncated: (amount_minor * bps) / 10000.
        // Exact integer arithmetic — no floats, no rounding up.
        $percentFeeMinor = (int) bcdiv((string) bcmul((string) $amountMinor, (string) $basisPoints, 0), '10000', 0);
        $feeMinor = $percentFeeMinor + $fixedMinor;

        if ($feeMinor >= $amountMinor) {
            throw new InvalidArgumentException('Fee must be smaller than the transfer amount.');
        }

        return [
            'fee_minor'   => $feeMinor,
            'fee_decimal' => MoneyAmount::fromMinorUnits($feeMinor, $currency)->decimal,
            'net_minor'   => $amountMinor - $feeMinor,
            'gross_minor' => $amountMinor,
        ];
    }

    /**
     * Fee schedule from config/payment_rails.php `transfers` section.
     *
     * @return array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int}
     */
    public function calculateFromConfig(int $amountMinor, string $currency): array
    {
        return $this->calculate(
            $amountMinor,
            $currency,
            (int) config('payment_rails.transfers.fee_basis_points', 0),
            (int) config('payment_rails.transfers.fee_fixed_minor', 0),
        );
    }
}
