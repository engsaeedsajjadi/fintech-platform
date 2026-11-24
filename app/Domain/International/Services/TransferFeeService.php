<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\Payment\Services\TransferFeeCalculator;

/**
 * International transfer fees.
 *
 * Fee = amount × basis_points / 10_000 + fixed, in integer minor units,
 * truncated so the fee can never exceed the configured rate (same exact
 * arithmetic as the domestic TransferFeeCalculator, reused here).
 * Defaults are ZERO — no fee is invented: an operator sets
 * `international.fee_basis_points` / `international.fee_fixed_minor`
 * explicitly. The fee is posted as a SEPARATE ledger entry from the
 * principal (see InternationalTransferService::process()).
 */
final class TransferFeeService
{
    public function __construct(
        private readonly TransferFeeCalculator $calculator,
    ) {
    }

    /** @return array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int} */
    public function calculate(int $amountMinor, string $currency): array
    {
        return $this->calculator->calculate(
            $amountMinor,
            $currency,
            (int) config('international.fee_basis_points', 0),
            (int) config('international.fee_fixed_minor', 0),
        );
    }
}
