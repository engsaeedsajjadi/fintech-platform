<?php

declare(strict_types=1);

use App\Domain\Asset\Exceptions\AssetNotFoundException;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\ExchangeRate;
use App\Domain\Asset\Services\AssetTransferService;
use App\Domain\Asset\Services\ExchangeRateService;
use App\Domain\Asset\ValueObjects\CurrencyPrecisionRegistry;
use App\Domain\Exchange\Contracts\IExchangeRateProvider;
use App\Domain\Exchange\Exceptions\RateProviderException;
use App\Domain\Exchange\Providers\MockExchangeRateProvider;
use App\Domain\Exchange\Services\ExchangeRateProviderRegistry;
use App\Domain\Exchange\ValueObjects\ExchangeRateQuote;
use App\Domain\Exchange\ValueObjects\RateProviderCapabilities;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\Services\AssetValuationService;
use App\Domain\Treasury\Services\PortfolioManagementService;
use App\Domain\Wallet\Exceptions\CustodyUnavailableException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LockNotFoundException;
use App\Domain\Wallet\Exceptions\RateUnavailableException;
use App\Domain\Wallet\Models\Conversion;
use App\Domain\Wallet\Services\ConversionService;
use App\Domain\Wallet\Services\CustodialWalletService;
use App\Domain\Wallet\Services\DecimalMath;
use App\Domain\Wallet\Services\DigitalAssetWalletService;
use App\Domain\Wallet\Services\FiatWalletService;
use App\Domain\Wallet\Services\WalletLimitsService;
use App\Domain\Wallet\Services\WalletOperationsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 3 (Wallet / multi-currency engine) hardening regressions.
 *
 * Each test below fails against the pre-fix code for the reason named in
 * its title, and passes after the fix. Nothing here depends on a live
 * rate provider, a real custodian, or production credentials.
 */
final class Phase3FixedRateProviderStub implements IExchangeRateProvider
{
    /** @param array<string, array<string, float>> $rates */
    public function __construct(private readonly array $rates = ['USD' => ['EUR' => 0.85], 'EUR' => ['USD' => 1.18], 'ETH' => ['USDC' => 3000.0]])
    {
    }

    public function getName(): string
    {
        return 'phase3-fixed-stub';
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

        return new ExchangeRateQuote($fromCurrency, $toCurrency, $rate, $rate * 0.999, $rate * 1.001, 'phase3-fixed-stub', Carbon::now());
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
    Cache::flush();
    config(['wallet.conversion.fee_bps' => 50]);
    $this->fiat = new FiatWalletService(app(LedgerService::class), new WalletLimitsService());
    $this->accountUuid = $this->account->uuid;
});

describe('currency precision registry', function () {
    it('defines code, display precision, ledger precision, minor unit and allowed operations per currency', function () {
        $usd = CurrencyPrecisionRegistry::for('USD');
        expect($usd->code)->toBe('USD')
            ->and($usd->assetClass)->toBe('fiat')
            ->and($usd->displayPrecision)->toBe(2)
            ->and($usd->ledgerPrecision)->toBe(4)
            ->and($usd->minorUnitExponent)->toBe(2)
            ->and($usd->minorUnitFactor)->toBe('100')
            ->and($usd->requiresMinorUnitPosting)->toBeFalse()
            ->and($usd->allowedOperations)->toContain('deposit', 'withdraw', 'transfer', 'convert');

        $jpy = CurrencyPrecisionRegistry::for('JPY');
        expect($jpy->displayPrecision)->toBe(0)->and($jpy->minorUnitFactor)->toBe('1');

        $irr = CurrencyPrecisionRegistry::for('IRR');
        expect($irr->displayPrecision)->toBe(0)->and($irr->minorUnitFactor)->toBe('1');
    });

    it('gives digital assets their native scale and flags minor-unit ledger posting', function () {
        $usdt = CurrencyPrecisionRegistry::for('USDT');
        expect($usdt->assetClass)->toBe('digital')
            ->and($usdt->displayPrecision)->toBe(6)
            ->and($usdt->minorUnitExponent)->toBe(6)
            ->and($usdt->minorUnitFactor)->toBe('1000000')
            ->and($usdt->requiresMinorUnitPosting)->toBeTrue()
            ->and($usdt->ledgerPrecision)->toBeNull();

        expect(CurrencyPrecisionRegistry::for('BTC')->displayPrecision)->toBe(8)
            ->and(CurrencyPrecisionRegistry::for('SOL')->displayPrecision)->toBe(9)
            ->and(CurrencyPrecisionRegistry::for('GCU')->requiresMinorUnitPosting)->toBeFalse();
    });

    it('refuses an unknown currency instead of guessing its precision', function () {
        expect(fn () => CurrencyPrecisionRegistry::for('XXX'))->toThrow(AssetNotFoundException::class);
        expect(CurrencyPrecisionRegistry::tryFor('XXX'))->toBeNull();
    });

    it('converts between major and minor units exactly, without float', function () {
        expect(CurrencyPrecisionRegistry::for('USDT')->toMinorUnits('29.85'))->toBe('29850000')
            ->and(CurrencyPrecisionRegistry::for('USDT')->fromMinorUnits('29850000'))->toBe('29.850000')
            ->and(CurrencyPrecisionRegistry::for('USD')->toMinorUnits('10.25'))->toBe('1025');
    });
});

describe('asset exchange-rate service: no fabricated rates', function () {
    it('returns null and stores nothing when the sandbox rate source is disabled', function () {
        config(['asset.sandbox_rates_enabled' => false]);

        $service = new ExchangeRateService();

        expect($service->fetchAndStoreRate('USD', 'EUR'))->toBeNull()
            ->and($service->getRate('USD', 'EUR'))->toBeNull()
            ->and(ExchangeRate::query()->where('from_asset_code', 'USD')->where('to_asset_code', 'EUR')->count())->toBe(0);
    });

    it('labels sandbox rates as sandbox_mock, never as a live API rate', function () {
        config(['asset.sandbox_rates_enabled' => true]);

        $service = new ExchangeRateService();
        $rate = $service->fetchAndStoreRate('USD', 'EUR');

        expect($rate)->not->toBeNull()
            ->and($rate->source)->toBe(ExchangeRate::SOURCE_SANDBOX)
            ->and($rate->metadata['provider'] ?? null)->toBe('sandbox_mock')
            ->and((string) $rate->rate)->toBe('0.8500000000');
    });

    it('computes chained sandbox rates exactly, without float multiplication', function () {
        config(['asset.sandbox_rates_enabled' => true]);

        $service = new ExchangeRateService();
        $rate = $service->fetchAndStoreRate('EUR', 'GBP');

        // 1.18 * 0.73 = 0.8614 exactly.
        expect($rate)->not->toBeNull()->and((string) $rate->rate)->toBe('0.8614000000');
    });
});

describe('exchange-rate float arithmetic', function () {
    it('converts large minor-unit amounts exactly instead of through float', function () {
        $rate = ExchangeRate::factory()->between('USD', 'EUR')->create([
            'rate' => 1.0, 'valid_at' => now()->subMinute(), 'expires_at' => now()->addHour(), 'is_active' => true,
        ]);

        // 2^53 + 1: a float can no longer represent this integer exactly.
        expect($rate->convert(9007199254740993))->toBe(9007199254740993);
    });

    it('formats large amounts exactly instead of through float', function () {
        Asset::query()->firstOrCreate(['code' => 'BTC'], ['name' => 'Bitcoin', 'type' => 'crypto', 'precision' => 8, 'is_active' => true]);
        $service = new AssetTransferService(new ExchangeRateService());

        expect($service->formatAmount('BTC', '9999999999999999.99'))->toBe('9999999999999999.99000000');
    });

    it('charges the configured conversion fee, not a hardcoded 0.1 percent', function () {
        config(['wallet.conversion.fee_bps' => 50]);
        Asset::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
        Asset::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
        ExchangeRate::factory()->between('USD', 'EUR')->create([
            'rate' => 0.85, 'valid_at' => now()->subMinute(), 'expires_at' => now()->addHour(), 'is_active' => true,
        ]);
        $service = new AssetTransferService(new ExchangeRateService());

        $result = $service->calculateConversion('USD', 'EUR', '100.00');

        // Converted 85.00; fee at 50 bps = 0.425; net 84.575.
        expect($result['fee_amount'])->toBe('0.42500000')->and($result['net_amount'])->toBe('84.57500000');
    });
});

describe('conversion service: sandbox rate provider gate', function () {
    it('refuses to price a quote from the mock provider when sandbox rates are disallowed', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('mock', new MockExchangeRateProvider(['name' => 'Mock Exchange Rate Provider']));
        $registry->setDefault('mock');
        config(['wallet.conversion.allow_sandbox_provider' => false]);
        $service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);

        expect(fn () => $service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00'))
            ->toThrow(RateUnavailableException::class);
        expect(Conversion::query()->count())->toBe(0);
    });

    it('labels a sandbox-priced quote as sandbox and records the serving provider', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('mock', new MockExchangeRateProvider(['name' => 'Mock Exchange Rate Provider']));
        $registry->setDefault('mock');
        config(['wallet.conversion.allow_sandbox_provider' => true]);
        $service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);

        $quote = $service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        expect($quote->provider)->toBe('mock:sandbox');
    });

    it('records the real serving provider name instead of the configured default', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('phase3-fixed-stub', new Phase3FixedRateProviderStub());
        $registry->setDefault('phase3-fixed-stub');
        config(['exchange.default_provider' => 'mock']);
        $service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);

        $quote = $service->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');

        expect($quote->provider)->toBe('phase3-fixed-stub');
    });

    it('settles a crypto conversion at the asset native precision', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('phase3-fixed-stub', new Phase3FixedRateProviderStub());
        $registry->setDefault('phase3-fixed-stub');
        $service = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);
        $digital = new DigitalAssetWalletService(new WalletLimitsService());
        // The asset-transfer projector FK-requires the target Asset row;
        // the precision fallback itself is covered by the reflection
        // tests below (USDT/SOL with no row at all).
        Asset::query()->firstOrCreate(['code' => 'USDC'], ['name' => 'USD Coin', 'type' => 'crypto', 'precision' => 6, 'is_active' => true]);

        $digital->createAssetAccount($this->accountUuid, 'ETH', 'ethereum');
        $digital->recordDeposit($this->accountUuid, 'ETH', 'ethereum', '0x' . str_repeat('a', 64), 10_000_000_000_000_000, null, null, 12);

        $quote = $service->createQuote($this->accountUuid, 'ETH', 'USDC', '0.01');
        $conversion = $service->execute($quote->uuid, 'conv-phase3-crypto');

        expect($conversion->status)->toBe(Conversion::STATUS_COMPLETED)
            ->and($digital->balanceMinor($this->accountUuid, 'ETH'))->toBe(0)
            ->and($digital->balanceMinor($this->accountUuid, 'USDC'))->toBe(29_850_000);
    });

    it('resolves precision from the registry when no Asset row exists', function () {
        // USDT and SOL have no seeded Asset row; the old hardcoded
        // fallbacks returned 8 (ConversionService / DigitalAssetWallet)
        // and 2 (AssetTransferService / ConvertAssetActivity).
        expect(Asset::query()->whereIn('code', ['USDT', 'SOL'])->exists())->toBeFalse();

        $conversion = new ConversionService(app(LedgerService::class), new WalletLimitsService(), new ExchangeRateProviderRegistry());
        $digital = new DigitalAssetWalletService(new WalletLimitsService());
        $transfer = new AssetTransferService(new ExchangeRateService());

        $precisionOf = fn (object $service, string $code): int => (int) (function () use ($code) {
            return $this->precisionOf($code);
        })->call($service);

        expect($precisionOf($conversion, 'USDT'))->toBe(6)
            ->and($precisionOf($conversion, 'SOL'))->toBe(9)
            ->and($precisionOf($digital, 'USDT'))->toBe(6)
            ->and($precisionOf($transfer, 'USDT'))->toBe(6)
            ->and($precisionOf($transfer, 'SOL'))->toBe(9);

        expect(fn () => $precisionOf($conversion, 'XXX'))->toThrow(AssetNotFoundException::class);
    });
});

describe('fiat reconciliation', function () {
    it('stays matched after a fiat conversion moves funds between currencies', function () {
        $registry = new ExchangeRateProviderRegistry();
        $registry->register('phase3-fixed-stub', new Phase3FixedRateProviderStub());
        $registry->setDefault('phase3-fixed-stub');
        $conversion = new ConversionService(app(LedgerService::class), new WalletLimitsService(), $registry);

        $this->fiat->deposit($this->accountUuid, 'USD', '100.00', 'dep-phase3-rec', 'prov-ref-1');
        $quote = $conversion->createQuote($this->accountUuid, 'USD', 'EUR', '100.00');
        $conversion->execute($quote->uuid, 'conv-phase3-rec');

        expect($this->fiat->reconcile($this->accountUuid, 'USD')['matched'])->toBeTrue()
            ->and($this->fiat->reconcile($this->accountUuid, 'EUR')['matched'])->toBeTrue()
            ->and($this->fiat->reconcile($this->accountUuid, 'EUR')['ledger_balance'])->toBe('84.5750');
    });
});

describe('exact decimal sums', function () {
    it('sums 0.1 and 0.2 exactly', function () {
        expect(DecimalMath::exactSum(['0.1', '0.2']))->toBe('0.3000')
            ->and(DecimalMath::exactSum([]))->toBe('0.0000');
    });

    it('does not reject a second deposit because of float error in the daily sum', function () {
        config([
            'wallet.limits.fiat.deposit_per_day'         => '0.30',
            'wallet.limits.fiat.deposit_per_transaction' => '100000',
            'wallet.fiat.fee_bps'                        => 0,
        ]);

        $this->fiat->deposit($this->accountUuid, 'USD', '0.10', 'dep-phase3-sum-1', 'prov-ref-1');
        $second = $this->fiat->deposit($this->accountUuid, 'USD', '0.20', 'dep-phase3-sum-2', 'prov-ref-1');

        expect($second->status)->toBe('completed')
            ->and($this->fiat->balance($this->accountUuid, 'USD'))->toBe('0.3000');
    });
});

describe('wallet fund reservation', function () {
    it('refuses a decimal minor-unit amount instead of silently truncating it to an integer', function () {
        $service = new WalletOperationsService(new ExchangeRateService());

        expect(fn () => $service->lockFunds($this->accountUuid, 'USD', '10.50', 'escrow'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $service->withdraw($this->accountUuid, 'USD', '10.50'))->toThrow(InvalidArgumentException::class);
    });

    it('locks funds, refuses to over-lock, and refuses a second unlock of the same reservation', function () {
        // Fund 10000 minor units of USD through the ACCOUNT aggregate —
        // the same stream the withdraw workflow debits from. (Funding
        // through the Asset aggregate's parallel stream would leave the
        // Account aggregate's replayed balance at 0 and the workflow
        // debit would fail asynchronously — see the Phase 3 report.)
        App\Domain\Account\Aggregates\AssetTransactionAggregate::retrieve($this->accountUuid)
            ->credit('USD', 10000)
            ->persist();

        $service = new WalletOperationsService(new ExchangeRateService());

        // KNOWN pre-existing Account-domain issue: AccountProjector and
        // AssetBalanceProjector are both auto-discovered and both apply
        // every AssetBalanceAdded / AssetBalanceSubtracted event to
        // AccountBalance, so one 10000 credit lands as 20000 (see
        // AccountProjectorTest: "even if doubled"). The reservation
        // mechanics below are verified against the balance the service
        // itself reports; the duplication is flagged in the Phase 3
        // report as out-of-scope Account work.
        expect($service->getBalance($this->accountUuid, 'USD'))->toBe('20000');

        // The synchronous guarantees of lockFunds: the balance check runs
        // now, and the reservation is recorded now. The workflow debit
        // itself lands asynchronously (WorkflowStub::start() leaves the
        // workflow in WorkflowWaitingStatus until a queue worker picks
        // it up), so no synchronous balance change is asserted here.
        $lockId = $service->lockFunds($this->accountUuid, 'USD', '5000', 'escrow');

        expect($lockId)->toStartWith('lock_');
        $reservation = decrypt(Cache::get('wallet_lock:' . $lockId));
        expect($reservation['amount'])->toBe('5000')
            ->and($reservation['wallet_id'])->toBe($this->accountUuid)
            ->and($reservation['asset_code'])->toBe('USD');

        expect(fn () => $service->lockFunds($this->accountUuid, 'USD', '999999999', 'escrow'))
            ->toThrow(InsufficientBalanceException::class);

        // Unlock claims the reservation exactly once: the first call
        // returns true and forgets it, the second finds nothing.
        expect($service->unlockFunds($lockId))->toBeTrue()
            ->and(Cache::has('wallet_lock:' . $lockId))->toBeFalse();

        expect(fn () => $service->unlockFunds($lockId))->toThrow(LockNotFoundException::class);
    });
});

describe('treasury valuation: no fabricated market prices', function () {
    it('refuses to value assets from mock prices when sandbox prices are disabled', function () {
        config(['treasury.sandbox_prices_enabled' => false]);
        $service = new AssetValuationService(app(PortfolioManagementService::class));

        expect(fn () => $service->getAssetPrices(['USD']))->toThrow(RuntimeException::class, 'no market-data provider');
    });

    it('labels sandbox prices as sandbox_mock, never as market data', function () {
        config(['treasury.sandbox_prices_enabled' => true]);
        $service = new AssetValuationService(app(PortfolioManagementService::class));

        $prices = $service->getAssetPrices(['USD']);

        expect($prices['USD']['source'])->toBe('sandbox_mock')
            ->and($prices['USD']['sandbox'])->toBeTrue();
    });
});

describe('production custody stays disabled', function () {
    it('fails closed when no custodian is configured', function () {
        config(['wallet.custody.provider' => '']);
        $service = app(CustodialWalletService::class);

        expect(fn () => $service->resolveCustodian())->toThrow(CustodyUnavailableException::class);
    });

    it('fails closed for a mock custodian in production', function () {
        config(['wallet.custody.provider' => 'mock']);
        $service = app(CustodialWalletService::class);

        try {
            app()->detectEnvironment(fn () => 'production');

            expect(fn () => $service->resolveCustodian())->toThrow(CustodyUnavailableException::class);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    });

    it('defaults to the non-custodial model', function () {
        expect((string) config('wallet.digital.custody.default'))->toBe('non-custodial');
    });
});
