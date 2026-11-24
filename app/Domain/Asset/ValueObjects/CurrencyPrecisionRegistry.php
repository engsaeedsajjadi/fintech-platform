<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObjects;

use App\Domain\Asset\Exceptions\AssetNotFoundException;
use App\Domain\Asset\Models\Asset;
use App\Domain\Ledger\ValueObjects\LedgerPrecision;
use App\Domain\Ledger\ValueObjects\MoneyAmount;

/**
 * Currency / asset precision registry (Phase 3).
 *
 * Single canonical definition per currency: code, display precision,
 * ledger precision, minor unit and allowed operations. The native scale
 * is NEVER invented here: it comes from LedgerPrecision (Phase 4.2,
 * reused — not duplicated) or from an operator-registered `assets`
 * row, which wins when present. An unknown code is refused
 * fail-closed instead of getting a guessed precision.
 *
 * Known inconsistency, kept visible rather than papered over: the
 * `assets` seed registers ETH at precision 18 (wei-correct) while
 * LedgerPrecision maps ETH to 8. A registered row wins, so seeded ETH
 * resolves to 18; an unregistered ETH falls back to the ledger map's 8.
 * The two pins must be reconciled by an operator decision before any
 * production ETH flow relies on the fallback.
 */
final class CurrencyPrecisionRegistry
{
    /** @var list<string> */
    private const ALLOWED_OPERATIONS = ['create', 'deposit', 'withdraw', 'transfer', 'convert'];

    private function __construct()
    {
    }

    public static function for(string $code): CurrencyPrecision
    {
        $precision = self::tryFor($code);
        if ($precision === null) {
            throw new AssetNotFoundException(strtoupper(trim($code)), 'Unknown currency or asset code: ' . strtoupper(trim($code)) . ' — no precision is registered for it.');
        }

        return $precision;
    }

    public static function tryFor(string $code): ?CurrencyPrecision
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $postingScale = (int) config('ledger.posting_scale', 4);

        $row = Asset::query()->where('code', $code)->first();
        if ($row !== null) {
            return self::fromAssetRow($row, $postingScale);
        }

        if (! LedgerPrecision::isKnownCurrency($code)) {
            return null;
        }

        return self::fromLedgerPrecision($code, $postingScale);
    }

    /**
     * All currencies the wallet is configured to support: fiat allow-list
     * plus every asset on a configured digital network, plus every code in
     * the ledger crypto map. Unknown codes are skipped, never invented.
     *
     * @return array<string, CurrencyPrecision> keyed by code
     */
    public static function all(): array
    {
        $codes = [];
        foreach ((array) config('wallet.fiat.currencies', []) as $currency) {
            $codes[] = strtoupper((string) $currency);
        }
        foreach ((array) config('wallet.digital.networks', []) as $network) {
            foreach ((array) ($network['assets'] ?? []) as $asset) {
                $codes[] = strtoupper((string) $asset);
            }
        }
        foreach (array_keys((array) config('ledger.precision.crypto', [])) as $asset) {
            $codes[] = strtoupper((string) $asset);
        }

        $out = [];
        foreach (array_unique($codes) as $code) {
            $precision = self::tryFor($code);
            if ($precision !== null) {
                $out[$code] = $precision;
            }
        }

        return $out;
    }

    private static function fromAssetRow(Asset $row, int $postingScale): CurrencyPrecision
    {
        $code = strtoupper((string) $row->code);
        $scale = (int) $row->precision;
        $class = match ((string) ($row->type ?? '')) {
            'fiat'      => 'fiat',
            'crypto'    => 'digital',
            'commodity' => 'commodity',
            'basket'    => 'basket',
            default     => LedgerPrecision::nativeScale($code, $postingScale) === MoneyAmount::minorUnitExponent($code) ? 'fiat' : 'digital',
        };

        return self::build($code, $class, $scale, $postingScale);
    }

    private static function fromLedgerPrecision(string $code, int $postingScale): CurrencyPrecision
    {
        $scale = LedgerPrecision::nativeScale($code, $postingScale);
        // LedgerPrecision derives fiat scales from the ISO-4217 minor-unit
        // exponent, so a code whose native scale equals that exponent is
        // fiat; every crypto in the ledger map has a scale above it (the
        // smallest crypto scale in the map is 4 for GCU vs exponent 2).
        $class = $scale === MoneyAmount::minorUnitExponent($code) ? 'fiat' : 'digital';

        return self::build($code, $class, $scale, $postingScale);
    }

    private static function build(string $code, string $class, int $scale, int $postingScale): CurrencyPrecision
    {
        $display = $class === 'fiat' ? MoneyAmount::minorUnitExponent($code) : $scale;
        $requiresMinor = $scale > $postingScale;

        return new CurrencyPrecision(
            code: $code,
            assetClass: $class,
            displayPrecision: $display,
            ledgerPrecision: $requiresMinor ? null : $postingScale,
            minorUnitExponent: $scale,
            minorUnitFactor: bcpow('10', (string) $scale),
            requiresMinorUnitPosting: $requiresMinor,
            allowedOperations: self::ALLOWED_OPERATIONS,
        );
    }
}
