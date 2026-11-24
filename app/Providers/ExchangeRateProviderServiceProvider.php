<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Asset\Services\ExchangeRateService;
use App\Domain\Exchange\Providers\FixerIoProvider;
use App\Domain\Exchange\Providers\MockExchangeRateProvider;
use App\Domain\Exchange\Services\EnhancedExchangeRateService;
use App\Domain\Exchange\Services\ExchangeRateProviderRegistry;
use Illuminate\Support\ServiceProvider;

class ExchangeRateProviderServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the provider registry as singleton
        $this->app->singleton(ExchangeRateProviderRegistry::class);

        // Bind the enhanced service with explicit resolution
        $this->app->singleton(
            EnhancedExchangeRateService::class,
            function ($app) {
                return new EnhancedExchangeRateService($app->make(ExchangeRateProviderRegistry::class));
            }
        );

        // Bind the enhanced service as the default exchange rate service
        $this->app->bind(ExchangeRateService::class, EnhancedExchangeRateService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $registry = app(ExchangeRateProviderRegistry::class);

        // Register mock provider.
        //
        // Phase 8: the mock provider returns RANDOM rates (rand() jitter
        // around fixed tables) and the enhanced service stores whatever
        // the registry returns as a real rate (provider label 'registry',
        // not 'sandbox_mock'). Registering it in production meant money
        // paths settled at invented prices. It is now registered only
        // outside production, whatever the config flag says — the flag
        // default is also env-gated in config/exchange.php, and the
        // deploy preflight FAILs an explicit opt-in. With no real
        // provider configured, getRate() throws and fetchAndStoreRate()
        // returns null (fail-closed: "no rate available").
        if (config('exchange.providers.mock.enabled', true) && ! app()->environment('production')) {
            $mockProvider = new MockExchangeRateProvider(config('exchange.providers.mock', []));
            $registry->register('mock', $mockProvider);
        }

        // Register Fixer.io provider
        if (config('exchange.providers.fixer.enabled', false)) {
            $fixerProvider = new FixerIoProvider(config('exchange.providers.fixer', []));
            $registry->register('fixer', $fixerProvider);
        }

        // Register other providers from config
        foreach (config('exchange.providers', []) as $name => $config) {
            if (in_array($name, ['mock', 'fixer'])) {
                continue; // Already handled above
            }

            if (! ($config['enabled'] ?? false)) {
                continue;
            }

            $providerClass = $config['class'] ?? null;

            if ($providerClass && class_exists($providerClass)) {
                $provider = new $providerClass($config);
                $registry->register($name, $provider);
            }
        }

        // Set default provider. Phase 8: setDefault() throws when the named
        // provider was never registered — in production the mock provider
        // is not registered (see above), so a default of 'mock' must be
        // skipped instead of crashing the boot.
        if ($default = config('exchange.default_provider')) {
            if ($registry->has($default)) {
                $registry->setDefault($default);
            }
        }

        // Schedule rate refresh if enabled
        if (config('exchange.auto_refresh.enabled', false)) {
            $this->scheduleRateRefresh();
        }
    }

    /**
     * Schedule automatic rate refresh.
     */
    private function scheduleRateRefresh(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $frequency = config('exchange.auto_refresh.frequency', 'hourly');

        $command = $schedule->job(new \App\Domain\Exchange\Jobs\RefreshExchangeRatesJob());

        switch ($frequency) {
            case 'every_minute':
                $command->everyMinute();
                break;
            case 'every_five_minutes':
                $command->everyFiveMinutes();
                break;
            case 'every_fifteen_minutes':
                $command->everyFifteenMinutes();
                break;
            case 'every_thirty_minutes':
                $command->everyThirtyMinutes();
                break;
            case 'hourly':
                $command->hourly();
                break;
            case 'daily':
                $command->daily();
                break;
            default:
                $command->hourly();
        }
    }
}
