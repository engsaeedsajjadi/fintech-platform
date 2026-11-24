<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

use App\Domain\Ledger\Exceptions\LedgerPrecisionException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use RuntimeException;

/**
 * Currency / asset-aware precision model for the ledger (Phase 4.2-F).
 *
 * The ledger stores journal lines as decimal(20,4) and posts at a global
 * scale of 4. That is MORE precision than any fiat currency needs (ISO-4217
 * minor units are 0, 2 or 3 decimals), so fiat amounts truncate only
 * sub-0.0001 dust — the pinned, documented behaviour. It is LESS precision
 * than digital assets need (BTC/ETH 8, SOL 9, USDT/USDC 6), so truncating
 * a crypto amount would silently destroy real value (1 sat = 0.00000001
 * BTC). Those amounts are therefore REJECTED here, fail-closed, instead of
 * being truncated. Unknown currency codes are refused above 4 decimals for
 * the same reason: the ledger cannot prove the precision, so it refuses
 * rather than guessing.
 *
 * Model (config `ledger.precision`):
 * - fiat: native scale = ISO-4217 minor-unit exponent (MoneyAmount).
 * - digital assets: native scale from the `crypto` map (default 8 for
 *   BTC/ETH-style assets, 6 for stablecoins, 9 for SOL).
 * - settlement / fees: posted at the global posting scale (4).
 *
 * Migration path (NOT applied here — analysed, not assumed): widening
 * journal_lines debit/credit to decimal(28,8) is safe and reversible
 * (existing 4dp data is unaffected), but it also requires the TigerBeetle
 * driver to move its minor-unit factor from 10^4 to 10^8 and a re-check of
 * every consumer that reads lines at scale 4. Until that migration lands,
 * crypto must be posted in minor units through a dedicated path, not
 * through this 4dp ledger.
 */
final class LedgerPrecision
{
    /** @var array<string, int> */
    private static array $cryptoScales = [
        'BTC'  => 8,
        'ETH'  => 8,
        'SOL'  => 9,
        'USDT' => 6,
        'USDC' => 6,
        'DAI'  => 6,
        'GCU'  => 4,
    ];

    /** ISO-4217 fiat codes (alphabetic). Anything not here and not in the crypto map is unknown. */
    private const FIAT_CODES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
        'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
        'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
        'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS',
        'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR',
        'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD',
        'LSL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU',
        'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK',
        'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP',
        'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS',
        'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VED', 'VES', 'VND', 'VUV', 'WST',
        'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWL',
    ];

    private function __construct()
    {
    }

    /** True when the code is a known fiat currency or a known digital asset. */
    public static function isKnownCurrency(string $currency): bool
    {
        $code = strtoupper(trim($currency));

        if (in_array($code, self::FIAT_CODES, true)) {
            return true;
        }

        $configured = config('ledger.precision.crypto', self::$cryptoScales);

        return (is_array($configured) && isset($configured[$code])) || isset(self::$cryptoScales[$code]);
    }

    /**
     * Native (smallest-unit) scale of a currency / asset code.
     * Fiat comes from the ISO-4217 minor-unit exponent; digital assets from
     * the configured crypto map; unknown codes resolve above the posting
     * scale so assertRepresentable() refuses their over-precision amounts.
     */
    public static function nativeScale(string $currency, int $postingScale = 4): int
    {
        $code = strtoupper(trim($currency));

        $configured = config('ledger.precision.crypto', self::$cryptoScales);

        if (is_array($configured) && isset($configured[$code])) {
            return (int) $configured[$code];
        }

        if (isset(self::$cryptoScales[$code])) {
            return self::$cryptoScales[$code];
        }

        if (in_array($code, self::FIAT_CODES, true)) {
            return MoneyAmount::minorUnitExponent($code);
        }

        // Unknown code: unprovable precision — report above the posting
        // scale so over-precision amounts are refused fail-closed.
        return $postingScale + 1;
    }

    /** True when the currency needs more decimals than the ledger stores. */
    public static function exceedsPostingScale(string $currency, int $postingScale = 4): bool
    {
        return self::nativeScale($currency, $postingScale) > $postingScale;
    }

    /**
     * Fail-closed check for one raw amount: when the currency's native
     * scale exceeds the posting scale AND the amount carries more decimals
     * than the posting scale, the ledger cannot represent it exactly and
     * must refuse instead of truncating value away.
     *
     * @throws RuntimeException
     */
    public static function assertRepresentable(mixed $amount, string $currency, int $postingScale = 4): void
    {
        if (! self::exceedsPostingScale($currency, $postingScale)) {
            return;
        }

        $scale = self::decimalScale($amount);

        if ($scale === null) {
            // Not a parseable number — normaliseLines() reports that.
            return;
        }

        if ($scale > $postingScale) {
            throw new LedgerPrecisionException(
                "Amount '{$amount}' {$currency} needs {$scale} decimals but the ledger posts at scale {$postingScale}; "
                . 'crypto amounts must be posted in minor units (see LedgerPrecision) — refusing instead of truncating value.'
            );
        }
    }

    /**
     * Number of decimal places in a raw amount, without float arithmetic.
     * Returns null when the value is not a finite decimal number.
     */
    public static function decimalScale(mixed $value): ?int
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = (string) $value;
        } elseif (is_int($value)) {
            return 0;
        } elseif (! is_string($value)) {
            return null;
        }

        try {
            return BigDecimal::of(trim($value))->getScale();
        } catch (MathException) {
            return null;
        }
    }
}
