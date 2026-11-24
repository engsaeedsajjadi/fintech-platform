<?php

declare(strict_types=1);

use App\Domain\Exchange\Contracts\IExchangeRateProvider;
use App\Domain\Exchange\Exceptions\RateProviderException;
use App\Domain\Exchange\Services\ExchangeRateProviderRegistry;
use App\Domain\Exchange\ValueObjects\ExchangeRateQuote;
use App\Domain\Exchange\ValueObjects\RateProviderCapabilities;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Exceptions\QuoteExpiredException;
use App\Domain\Wallet\Exceptions\RateUnavailableException;
use App\Domain\Wallet\Models\Conversion;
use App\Domain\Wallet\Models\ConversionQuote;
use App\Domain\Wallet\Services\ConversionService;
use App\Domain\Wallet\Services\FiatWalletService;
use App\Domain\Wallet\Services\WalletLimitsService;
use Carbon\Carbon;

/** Deterministic stub rate provider: fixed rates, no randomness, no invention. */
final class FixedRateProviderStub implements IExchangeRateProvider
{
    /** @param array<string, array<string, float>> $rates */
    public function __construct(private readonly array $rates = ['USD' => ['EUR' => 0.85], 'EUR' => ['USD' => 1.18], 'ETH' => ['USDC' => 3000.0]])
    {
    }

    public function getName(): string
    {
        return 'fixed-stub';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getRate(string $fromCurrency, string $toCurrency): ExchangeRateQuote
    {
        if (! isset($this->rates[$fromCurrency][$toCurrency])) {
            throw new RateProviderException("Currency pair {$fromCurrency}/{$toCurrency} not supported");
        }
        $rate = $this->rates[$fromCurrency][$toCurrency];

        return new ExchangeRateQuote($fromCurrency, $toCurrency, $rate, $rate * 0.999, $rate * 1.001, 'fixed-stub', Carbon::now());
    }

    public function getRates(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            [$from, $to] = explode('/', $pair);
            $out[$pair] = $this->getRate($from, $to);
        }

        return $out;
    }

    public function getAllRatesForBase(string $baseCurrency): array
    {
        $out = [];
        foreach ($this->rates[$baseCurrency] ?? [] as $to => $rate) {
            $out["{$baseCurrency}/{$to}"] = $this->getRate($baseCurrency, $to);
        }

        return $out;
    }

    public function getCapabilities(): RateProviderCapabilities
    {
        return new RateProviderCapabilities(true, true, true);
    }

    public function getSupportedCurrencies(): array
    {
        return array_keys($this->rates);
    }

    public function supportsPair(string $fromCurrency, string $toCurrency): bool
    {
        return isset($this->rates[$fromCurrency][$toCurrency]);
    }

    public function getPriority(): int
    {
        return 100;
    }
}

beforeEach(function () {
    $registry = new ExchangeRateProviderRegistry();
    $registry->register('fixed-stub', new FixedRateProviderStub());
    $registry->setDefault('fixed-stub');
    $this->service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);
    $this->fiat = new FiatWalletService(app(LedgerService::class), new WalletLimitsService());
    $this->accountUuid = $this->account->uuid;
    config(['wallet.conversion.fee_bps' => 50]); // 0.5%
});

describe('conversion quotes', function () {
    it('generates a quote with an exact decimal rate and fee, separate from execution', function () {
        $quote = $this->service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        expect($quote->status)->toBe(ConversionQuote::STATUS_ACTIVE)
            ->and((string) $quote->rate)->toBe('0.8500000000')
            ->and((string) $quote->fee)->toBe('0.500000000000000000')
            // (100 - 0.50) * 0.85 = 84.575
            ->and((string) $quote->converted_amount)->toBe('84.575000000000000000')
            ->and($quote->expires_at->isFuture())->toBeTrue();

        // Pricing only: no ledger entry, no balance change.
        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('fails explicitly when no rate exists — a rate is never invented', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('fixed-stub', new FixedRateProviderStub([]));
        $registry->setDefault('fixed-stub');
        $service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);

        expect(fn () => $service->createQuote($this->accountUuid, 'USD', 'JPY', '100.00'))
            ->toThrow(RateUnavailableException::class);
    });

    it('previews the full breakdown for customer confirmation', function () {
        $quote = $this->service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        $preview = $this->service->preview($quote->uuid);

        expect($preview['from_asset'])->toBe('USD')
            ->and($preview['to_asset'])->toBe('EUR')
            ->and($preview['converted_amount'])->toBe('84.575000000000000000')
            ->and($preview['expired'])->toBeFalse();
    });
});

describe('conversion execution', function () {
    it('settles a fiat conversion through balanced ledger entries', function () {
        $this->fiat->deposit($this->accountUuid, 'USD', '100.00', 'dep-conv', 'prov-ref-1');
        $quote = $this->service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        $conversion = $this->service->execute($quote->uuid, 'conv-1');

        expect($conversion->status)->toBe(Conversion::STATUS_COMPLETED)
            ->and((string) $conversion->to_amount)->toBe('84.575000000000000000')
            ->and($this->fiat->balance($this->accountUuid, 'USD'))->toBe('0.0000')
            ->and($this->fiat->balance($this->accountUuid, 'EUR'))->toBe('84.5750');

        foreach ($conversion->journal_entry_ids as $entryId) {
            $entry = JournalEntry::query()->findOrFail($entryId);
            expect($entry->status->value)->toBe('posted');
        }
    });

    it('refuses to execute an expired quote', function () {
        $this->fiat->deposit($this->accountUuid, 'USD', '100.00', 'dep-exp', 'prov-ref-1');
        $quote = $this->service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');
        $quote->update(['expires_at' => Carbon::now()->subMinute()]);

        expect(fn () => $this->service->execute($quote->uuid, 'conv-exp'))
            ->toThrow(QuoteExpiredException::class);
        expect($quote->refresh()->status)->toBe(ConversionQuote::STATUS_EXPIRED)
            ->and($this->fiat->balance($this->accountUuid, 'USD'))->toBe('100.0000');
        expect(Conversion::query()->count())->toBe(0);
    });

    it('is idempotent: duplicate execution settles once', function () {
        $this->fiat->deposit($this->accountUuid, 'USD', '100.00', 'dep-idem', 'prov-ref-1');
        $quote = $this->service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        $first = $this->service->execute($quote->uuid, 'conv-idem');
        $entriesAfterFirst = JournalEntry::query()->count();

        $second = $this->service->execute($quote->uuid, 'conv-idem');
        $third = $this->service->execute($quote->uuid, 'conv-other-key');

        expect($second->id)->toBe($first->id)
            ->and($third->id)->toBe($first->id)
            ->and(Conversion::query()->count())->toBe(1)
            ->and(JournalEntry::query()->count())->toBe($entriesAfterFirst)
            ->and($this->fiat->balance($this->accountUuid, 'EUR'))->toBe('84.5750');
    });

    it('settles a crypto conversion through the asset aggregate (full debit, fee retained)', function () {
        $digital = new App\Domain\Wallet\Services\DigitalAssetWalletService(new WalletLimitsService());
        $digital->createAssetAccount($this->accountUuid, 'ETH', 'ethereum');
        App\Domain\Asset\Models\Asset::query()->firstOrCreate(['code' => 'USDC'], ['name' => 'USD Coin', 'type' => 'crypto', 'precision' => 6, 'is_active' => true]);
        // Fund 0.01 ETH (10_000_000_000_000_000 wei).
        $digital->recordDeposit($this->accountUuid, 'ETH', 'ethereum', '0x' . str_repeat('a', 64), 10_000_000_000_000_000, null, null, 12);

        $quote = $this->service->createQuote($this->accountUuid, 'ETH', 'USDC', '0.01');
        // Fee 0.5%: (0.01 - 0.00005) * 3000 = 29.85 USDC.
        expect((string) $quote->converted_amount)->toBe('29.850000000000000000');

        $conversion = $this->service->execute($quote->uuid, 'conv-crypto-1');

        expect($conversion->status)->toBe(Conversion::STATUS_COMPLETED)
            ->and($digital->balanceMinor($this->accountUuid, 'ETH'))->toBe(0)
            ->and($digital->balanceMinor($this->accountUuid, 'USDC'))->toBe(29_850_000);
    });
});
