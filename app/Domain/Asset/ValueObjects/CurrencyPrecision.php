<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObjects;

use App\Domain\Asset\Models\Asset;
use App\Domain\Ledger\ValueObjects\LedgerPrecision;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One currency / asset precision definition.
 *
 * - code: ISO-4217 fiat code or digital-asset code, upper-case.
 * - assetClass: 'fiat' | 'digital' | 'commodity' | 'basket'.
 * - displayPrecision: decimals shown to the user (fiat: ISO-4217 minor
 *   unit; digital: native scale).
 * - ledgerPrecision: decimals at which the ledger stores major-unit
 *   amounts, or null when the ledger cannot store the asset in major
 *   units at all (native scale exceeds the posting scale — the asset must
 *   be posted in minor units, see LedgerPrecision).
 * - minorUnitExponent: native scale; minorUnitFactor = 10^exponent.
 * - allowedOperations: wallet operations permitted for this currency.
 */
final readonly class CurrencyPrecision
{
    /**
     * @param list<string> $allowedOperations
     */
    public function __construct(
        public string $code,
        public string $assetClass,
        public int $displayPrecision,
        public ?int $ledgerPrecision,
        public int $minorUnitExponent,
        public string $minorUnitFactor,
        public bool $requiresMinorUnitPosting,
        public array $allowedOperations,
    ) {
    }

    /** Exact major -> minor conversion, no float. @return numeric-string */
    public function toMinorUnits(string $majorAmount): string
    {
        return (string) BigDecimal::of($majorAmount)
            ->multipliedBy(BigDecimal::of($this->minorUnitFactor))
            ->toScale(0, RoundingMode::DOWN);
    }

    /** Exact minor -> major conversion, no float. @return numeric-string */
    public function fromMinorUnits(string $minorAmount): string
    {
        return (string) BigDecimal::of($minorAmount)
            ->dividedBy(BigDecimal::of($this->minorUnitFactor), $this->minorUnitExponent, RoundingMode::DOWN);
    }
}
