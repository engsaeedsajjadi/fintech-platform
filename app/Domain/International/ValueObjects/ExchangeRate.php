<?php

declare(strict_types=1);

namespace App\Domain\International\ValueObjects;

use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use InvalidArgumentException;

/**
 * An exchange rate between two ISO-4217 currencies.
 *
 * The rate is a decimal string (destination major units per 1 source
 * major unit) supplied by an operator / treasury desk — there is no live
 * FX feed in this codebase and none is invented. Conversion is exact
 * bcmath, truncated (rounded DOWN) so a quotation can never promise
 * more destination currency than the rate supports.
 */
final readonly class ExchangeRate
{
    public string $sourceCurrency;

    public string $destinationCurrency;

    /** @var numeric-string */
    public string $rate;

    public function __construct(string $sourceCurrency, string $destinationCurrency, string $rate)
    {
        $sourceCurrency = strtoupper($sourceCurrency);
        $destinationCurrency = strtoupper($destinationCurrency);

        if (! preg_match('/^[A-Z]{3}$/', $sourceCurrency) || ! preg_match('/^[A-Z]{3}$/', $destinationCurrency)) {
            throw new InvalidArgumentException('Currencies must be ISO-4217 alpha-3 codes.');
        }

        if (! is_numeric($rate) || DecimalMath::cmp($rate, '0', 10) <= 0) {
            throw new InvalidArgumentException('Exchange rate must be a positive decimal string.');
        }

        $this->sourceCurrency = $sourceCurrency;
        $this->destinationCurrency = $destinationCurrency;
        /** @var numeric-string $rate */
        $this->rate = $rate;
    }

    /**
     * Convert integer minor units of the source currency to integer minor
     * units of the destination currency, truncated towards zero.
     */
    public function convertMinor(int $sourceMinor): int
    {
        $srcExp = MoneyAmount::minorUnitExponent($this->sourceCurrency);
        $dstExp = MoneyAmount::minorUnitExponent($this->destinationCurrency);

        // source major = minor / 10^srcExp; dest major = major * rate;
        // dest minor = dest major * 10^dstExp. One bcmath chain, scale 10,
        // truncated at the end so the quote never over-promises.
        $sourceMajor = bcdiv((string) $sourceMinor, bcpow('10', (string) $srcExp), 10);
        $destMajor = bcmul($sourceMajor, $this->rate, 10);
        $destMinor = bcmul($destMajor, bcpow('10', (string) $dstExp), 0);

        return (int) $destMinor;
    }
}
