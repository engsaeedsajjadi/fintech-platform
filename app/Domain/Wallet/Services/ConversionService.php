<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\ValueObjects\CurrencyPrecisionRegistry;
use App\Domain\Exchange\Exceptions\RateProviderException;
use App\Domain\Exchange\Providers\MockExchangeRateProvider;
use App\Domain\Exchange\Services\ExchangeRateProviderRegistry;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\InvalidAssetException;
use App\Domain\Wallet\Exceptions\QuoteExpiredException;
use App\Domain\Wallet\Exceptions\RateUnavailableException;
use App\Domain\Wallet\Exceptions\UnsupportedConversionException;
use App\Domain\Wallet\Models\Conversion;
use App\Domain\Wallet\Models\ConversionQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Conversion engine — pricing is SEPARATE from execution.
 *
 * Quote generation (createQuote) fetches a rate from
 * IExchangeRateProvider via ExchangeRateProviderRegistry and locks it
 * into a ConversionQuote with an expiry. No rate is ever invented: if no
 * provider returns a rate, quote generation fails with
 * RateUnavailableException. The rate is stored as an exact decimal
 * string (scale 10); the provider's float is converted once, at this
 * boundary, via DecimalMath::rateFromFloat() / BigDecimal.
 *
 * Execution (execute) settles ONLY the locked quote: it re-checks expiry
 * (an expired quote cannot execute), enforces limits, and settles
 * idempotently (same idempotency key => same Conversion, never twice).
 * Customer confirmation is the act of calling execute() with a quote the
 * customer previewed; preview() exposes the full breakdown first.
 *
 * Settlement paths (v1): fiat<->fiat via balanced LedgerService::post()
 * entries; crypto<->crypto via AssetTransferAggregate (projector moves
 * AccountBalance). Fiat<->crypto is NOT supported in v1 — there is no
 * licensed off-ramp/settlement provider — and throws
 * UnsupportedConversionException. Off-ramp stays explicitly unavailable
 * (see Ramp OfframpNotAvailableException).
 *
 * Each leg is a separate balanced entry with its own per-line currency
 * (LedgerService::post() accepts a per-line `currency`), so no entry
 * ever mixes currencies.
 */
final class ConversionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly WalletLimitsService $limits,
        private readonly ExchangeRateProviderRegistry $rateRegistry,
    ) {
    }

    // ------------------------------------------------------------------
    // Pricing (quote generation)
    // ------------------------------------------------------------------

    public function createQuote(string $accountUuid, string $fromAsset, string $toAsset, string $amount): ConversionQuote
    {
        $fromAsset = strtoupper(trim($fromAsset));
        $toAsset = strtoupper(trim($toAsset));
        DecimalMath::assertPositiveDecimal($amount);
        if ($fromAsset === $toAsset) {
            throw new InvalidArgumentException('Conversion requires two different assets.');
        }
        $this->assertKnownAsset($fromAsset);
        $this->assertKnownAsset($toAsset);

        $rate = $this->fetchRate($fromAsset, $toAsset);
        $fee = FeeCalculator::calculate(
            $amount,
            (int) config('wallet.conversion.fee_bps', 50),
            '0',
            '0',
            '0',
            18,
        );
        $net = (string) BigDecimal::of($amount)->minus(BigDecimal::of($fee))->toScale(18, RoundingMode::DOWN);
        $converted = (string) BigDecimal::of($net)->multipliedBy(BigDecimal::of($rate['rate']))->toScale(18, RoundingMode::DOWN);
        if (BigDecimal::of($converted)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException('The amount is too small to convert after fees.');
        }

        $quote = ConversionQuote::query()->create([
            'uuid'             => (string) Str::uuid(),
            'account_uuid'     => $accountUuid,
            'from_asset'       => $fromAsset,
            'to_asset'         => $toAsset,
            'amount'           => DecimalMath::scale18($amount),
            'rate'             => $rate['rate'],
            'fee'              => DecimalMath::scale18($fee),
            'converted_amount' => $converted,
            'provider'         => $rate['provider'],
            'status'           => ConversionQuote::STATUS_ACTIVE,
            'expires_at'       => Carbon::now()->addSeconds((int) config('wallet.conversion.ttl_seconds', 60)),
        ]);

        return $quote->refresh();
    }

    /** Preview: full quote breakdown for customer confirmation. @return array<string, mixed> */
    public function preview(string $quoteUuid): array
    {
        $quote = ConversionQuote::query()->where('uuid', $quoteUuid)->firstOrFail();

        return [
            'quote_uuid'       => $quote->uuid,
            'account_uuid'     => $quote->account_uuid,
            'from_asset'       => $quote->from_asset,
            'to_asset'         => $quote->to_asset,
            'amount'           => (string) $quote->amount,
            'rate'             => (string) $quote->rate,
            'fee'              => (string) $quote->fee,
            'converted_amount' => (string) $quote->converted_amount,
            'provider'         => $quote->provider,
            'status'           => $quote->status,
            'expires_at'       => $quote->expires_at?->toIso8601String(),
            'expired'          => $quote->isExpired(),
        ];
    }

    public function cancelQuote(string $quoteUuid): ConversionQuote
    {
        $quote = ConversionQuote::query()->where('uuid', $quoteUuid)->firstOrFail();
        if ($quote->status === ConversionQuote::STATUS_ACTIVE) {
            $quote->update(['status' => ConversionQuote::STATUS_CANCELLED]);
        }

        return $quote->refresh();
    }

    // ------------------------------------------------------------------
    // Execution (settlement of a locked quote)
    // ------------------------------------------------------------------

    public function execute(string $quoteUuid, string $idempotencyKey, ?string $confirmedBy = null): Conversion
    {
        // Idempotency first: a replayed key returns the original settlement.
        $existing = Conversion::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Expiry is checked (and the quote marked expired) in its own
        // committed write BEFORE the settlement transaction: marking it
        // inside the settlement transaction would roll back with the
        // QuoteExpiredException thrown below.
        $quote = ConversionQuote::query()->where('uuid', $quoteUuid)->firstOrFail();
        if ($quote->status === ConversionQuote::STATUS_EXECUTED) {
            $settled = Conversion::query()->where('quote_uuid', $quoteUuid)->first();
            if ($settled !== null) {
                return $settled;
            }
            throw new InvalidArgumentException("Quote {$quoteUuid} is marked executed but has no settlement record.");
        }
        if ($quote->status !== ConversionQuote::STATUS_ACTIVE || $quote->isExpired()) {
            if ($quote->status === ConversionQuote::STATUS_ACTIVE) {
                $quote->update(['status' => ConversionQuote::STATUS_EXPIRED]);
            }
            throw new QuoteExpiredException($quoteUuid);
        }

        return DB::transaction(function () use ($quoteUuid, $idempotencyKey, $confirmedBy): Conversion {
            $existing = Conversion::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $quote = ConversionQuote::query()->where('uuid', $quoteUuid)->lockForUpdate()->firstOrFail();
            if ($quote->status === ConversionQuote::STATUS_EXECUTED) {
                $settled = Conversion::query()->where('quote_uuid', $quoteUuid)->first();
                if ($settled !== null) {
                    return $settled;
                }
                throw new InvalidArgumentException("Quote {$quoteUuid} is marked executed but has no settlement record.");
            }
            if ($quote->status !== ConversionQuote::STATUS_ACTIVE || $quote->isExpired()) {
                throw new QuoteExpiredException($quoteUuid);
            }

            $fromFiat = $this->isFiat($quote->from_asset);
            $toFiat = $this->isFiat($quote->to_asset);
            if ($fromFiat !== $toFiat) {
                throw new UnsupportedConversionException('Fiat<->crypto conversion is not available in v1 (no licensed off-ramp/settlement provider). Convert within fiat or within digital assets.');
            }

            $conversion = $fromFiat
                ? $this->settleFiat($quote, $idempotencyKey)
                : $this->settleAssets($quote, $idempotencyKey);

            $quote->update(['status' => ConversionQuote::STATUS_EXECUTED, 'executed_at' => Carbon::now()]);

            return $conversion;
        });
    }

    /** Conversion history for one account. */
    public function history(string $accountUuid, int $limit = 100): array
    {
        return Conversion::query()->where('account_uuid', $accountUuid)->latest()->limit($limit)->get()
            ->map(fn (Conversion $c): array => [
                'uuid'        => $c->uuid,
                'from_asset'  => $c->from_asset,
                'to_asset'    => $c->to_asset,
                'from_amount' => (string) $c->from_amount,
                'fee'         => (string) $c->fee,
                'to_amount'   => (string) $c->to_amount,
                'rate'        => (string) $c->rate,
                'status'      => $c->status,
                'at'          => $c->created_at?->toIso8601String(),
            ])->all();
    }

    // ------------------------------------------------------------------
    // Settlement
    // ------------------------------------------------------------------

    private function settleFiat(ConversionQuote $quote, string $idempotencyKey): Conversion
    {
        $from = $quote->from_asset;
        $to = $quote->to_asset;
        $amount = DecimalMath::scale4((string) $quote->amount);
        $fee = DecimalMath::scale4((string) $quote->fee);
        $converted = DecimalMath::scale4((string) $quote->converted_amount);
        $net = DecimalMath::sub($amount, $fee);

        $this->limits->checkFiatConversion($quote->account_uuid, $amount);
        $available = DecimalMath::scale4($this->ledger->getBalance(FiatWalletService::walletAccountCode($quote->account_uuid, $from))['amount']);
        if (DecimalMath::cmp($available, $amount) < 0) {
            throw new InsufficientBalanceException($quote->account_uuid, $from, $amount, $available);
        }

        $this->ensureLedgerAccount("WALLET:CONVERSION:{$from}", "Conversion clearing {$from}", AccountType::ASSET, $from);
        $this->ensureLedgerAccount("WALLET:CONVERSION:{$to}", "Conversion clearing {$to}", AccountType::ASSET, $to);

        // Leg 1 (source currency): user wallet -> conversion clearing + fees.
        $leg1Lines = [
            ['account_code' => FiatWalletService::walletAccountCode($quote->account_uuid, $from), 'debit' => $amount, 'credit' => '0.0000', 'currency' => $from, 'narrative' => "Conversion {$quote->uuid} {$from} leg"],
            ['account_code' => "WALLET:CONVERSION:{$from}", 'debit' => '0.0000', 'credit' => $net, 'currency' => $from, 'narrative' => "Conversion {$quote->uuid} {$from} clearing"],
        ];
        if (DecimalMath::cmp($fee, '0') > 0) {
            $leg1Lines[] = ['account_code' => FiatWalletService::feesAccountCode($from), 'debit' => '0.0000', 'credit' => $fee, 'currency' => $from, 'narrative' => "Conversion {$quote->uuid} fee"];
        }
        $leg1 = $this->ledger->post("Conversion {$quote->uuid} {$from} -> {$to} (source leg, {$from})", $leg1Lines, 'Wallet', $quote->uuid);

        // Leg 2 (target currency): conversion clearing -> user wallet.
        $leg2 = $this->ledger->post(
            "Conversion {$quote->uuid} {$from} -> {$to} (target leg, {$to})",
            [
                ['account_code' => "WALLET:CONVERSION:{$to}", 'debit' => $converted, 'credit' => '0.0000', 'currency' => $to, 'narrative' => "Conversion {$quote->uuid} {$to} clearing"],
                ['account_code' => FiatWalletService::walletAccountCode($quote->account_uuid, $to), 'debit' => '0.0000', 'credit' => $converted, 'currency' => $to, 'narrative' => "Conversion {$quote->uuid} {$to} leg"],
            ],
            'Wallet',
            $quote->uuid,
        );

        return Conversion::query()->create([
            'uuid'              => (string) Str::uuid(),
            'account_uuid'      => $quote->account_uuid,
            'quote_uuid'        => $quote->uuid,
            'from_asset'        => $from,
            'to_asset'          => $to,
            'from_amount'       => DecimalMath::scale18((string) $quote->amount),
            'fee'               => DecimalMath::scale18((string) $quote->fee),
            'to_amount'         => DecimalMath::scale18((string) $quote->converted_amount),
            'rate'              => (string) $quote->rate,
            'provider'          => $quote->provider,
            'status'            => Conversion::STATUS_COMPLETED,
            'idempotency_key'   => $idempotencyKey,
            'journal_entry_ids' => [$leg1->id, $leg2->id],
        ]);
    }

    private function settleAssets(ConversionQuote $quote, string $idempotencyKey): Conversion
    {
        $fromPrecision = $this->precisionOf($quote->from_asset);
        $toPrecision = $this->precisionOf($quote->to_asset);
        $fromMinor = DecimalMath::toMinorUnits((string) $quote->amount, $fromPrecision);
        $toMinor = DecimalMath::toMinorUnits((string) $quote->converted_amount, $toPrecision);
        if ($fromMinor <= 0 || $toMinor <= 0) {
            throw new InvalidArgumentException('The amount is too small to convert after fees.');
        }

        $this->limits->checkDigitalConversion($fromMinor);
        $available = (int) (AccountBalance::query()
            ->where('account_uuid', $quote->account_uuid)
            ->where('asset_code', $quote->from_asset)
            ->value('balance') ?? 0);
        if ($available < $fromMinor) {
            throw new InsufficientBalanceException($quote->account_uuid, $quote->from_asset, (string) $fromMinor, (string) $available);
        }

        // The FULL source amount is debited and only the converted amount
        // is credited: the fee (fromMinor - netMinor) is the platform
        // spread, retained by the platform and recorded on the Conversion
        // row. (Debiting only the net would leave the fee in the user's
        // wallet, i.e. no fee at all.) A platform fee-asset booking is a
        // follow-up once a platform asset account exists; until then the
        // fee is visible on the Conversion record, not silently dropped.
        $transferUuid = $this->deterministicUuid("conversion:{$idempotencyKey}");
        AssetTransferAggregate::retrieve($transferUuid)
            ->initiate(
                AccountUuid::fromString($quote->account_uuid),
                AccountUuid::fromString($quote->account_uuid),
                $quote->from_asset,
                $quote->to_asset,
                new Money($fromMinor),
                new Money($toMinor),
                (float) $quote->rate, // Aggregate's pre-existing float field; the decimal-string rate on this row is authoritative.
                'Wallet currency conversion',
            )
            ->complete($transferUuid)
            ->persist();

        return Conversion::query()->create([
            'uuid'                => (string) Str::uuid(),
            'account_uuid'        => $quote->account_uuid,
            'quote_uuid'          => $quote->uuid,
            'from_asset'          => $quote->from_asset,
            'to_asset'            => $quote->to_asset,
            'from_amount'         => DecimalMath::scale18((string) $quote->amount),
            'fee'                 => DecimalMath::scale18((string) $quote->fee),
            'to_amount'           => DecimalMath::scale18((string) $quote->converted_amount),
            'rate'                => (string) $quote->rate,
            'provider'            => $quote->provider,
            'status'              => Conversion::STATUS_COMPLETED,
            'idempotency_key'     => $idempotencyKey,
            'asset_transfer_uuid' => $transferUuid,
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Fetch a rate from the first available provider that supports the
     * pair (highest registry priority first), and report WHICH provider
     * served it. The old code stored config('exchange.default_provider')
     * on the quote regardless of who actually served the rate — a quote
     * priced by the mock provider was labelled as if a real provider had
     * priced it.
     *
     * Fail-closed on sandbox rates: when the serving provider is a mock /
     * sandbox provider and config('wallet.conversion.allow_sandbox_provider')
     * is off (production default), no quote is created at all. A
     * sandbox-priced quote is labelled `<provider>:sandbox` on the quote.
     *
     * @return array{rate: numeric-string, provider: string}
     */
    private function fetchRate(string $from, string $to): array
    {
        $lastError = null;
        foreach ($this->rateRegistry->byPriority() as $name => $provider) {
            try {
                if (! $provider->isAvailable() || ! $provider->supportsPair($from, $to)) {
                    continue;
                }
                $quote = $provider->getRate($from, $to);
            } catch (Exception $e) {
                $lastError = $e;

                continue;
            }

            $decimal = $quote instanceof BigDecimal ? $quote : BigDecimal::of((string) $quote->rate);
            if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new RateUnavailableException($from, $to, "Rate provider '{$name}' returned a non-positive rate for {$from} to {$to}.");
            }

            $providerName = (string) $name;
            if ($this->isSandboxProvider($providerName, $provider)) {
                if (! (bool) config('wallet.conversion.allow_sandbox_provider', false)) {
                    throw new RateUnavailableException($from, $to, "Only a sandbox/mock rate provider is available for {$from} to {$to}, and sandbox pricing is disabled (wallet.conversion.allow_sandbox_provider). No rate was invented.");
                }
                $providerName .= ':sandbox';
            }

            /** @var numeric-string $rateString */
            $rateString = (string) $decimal->toScale(10, RoundingMode::DOWN);

            return [
                'rate'     => $rateString,
                'provider' => $providerName,
            ];
        }

        if ($lastError instanceof RateProviderException) {
            throw new RateUnavailableException($from, $to, "No exchange rate is available for {$from} to {$to}: {$lastError->getMessage()}");
        }

        throw new RateUnavailableException($from, $to);
    }

    private function isSandboxProvider(string $name, mixed $provider): bool
    {
        if (str_contains(strtolower($name), 'mock')) {
            return true;
        }

        return $provider instanceof MockExchangeRateProvider;
    }

    private function isFiat(string $asset): bool
    {
        return in_array(strtoupper($asset), array_map('strtoupper', array_map('strval', (array) config('wallet.fiat.currencies', ['USD']))), true);
    }

    private function assertKnownAsset(string $asset): void
    {
        if ($this->isFiat($asset)) {
            return;
        }
        if (Asset::query()->where('code', $asset)->exists()) {
            return;
        }
        $digitalAssets = [];
        foreach ((array) config('wallet.digital.networks', []) as $network) {
            foreach ((array) ($network['assets'] ?? []) as $code) {
                $digitalAssets[] = strtoupper((string) $code);
            }
        }
        if (in_array($asset, $digitalAssets, true)) {
            return;
        }
        throw new InvalidAssetException("Asset {$asset} is not a known fiat currency, digital asset, or registered Asset.");
    }

    /**
     * Precision of an asset: the registered `assets` row first, then the
     * canonical currency registry (LedgerPrecision-backed). The old
     * hardcoded fallback of 8 mis-scaled every unregistered asset (e.g.
     * USDT at 8 instead of 6 — a 100x minor-unit error on conversion).
     */
    private function precisionOf(string $assetCode): int
    {
        $row = Asset::query()->where('code', strtoupper($assetCode))->value('precision');
        if ($row !== null) {
            return (int) $row;
        }

        return CurrencyPrecisionRegistry::for($assetCode)->minorUnitExponent;
    }

    private function ensureLedgerAccount(string $code, string $name, AccountType $type, string $currency): void
    {
        LedgerAccount::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type->value, 'currency' => $currency, 'is_active' => true],
        );
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = substr(hash('sha256', $seed), 0, 32);
        $hash[12] = '4';
        $hash[16] = dechex(0x8 | (hexdec($hash[16]) & 0x3));

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}
