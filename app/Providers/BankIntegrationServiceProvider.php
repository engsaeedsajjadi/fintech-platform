<?php

namespace App\Providers;

use App\Domain\Banking\Connectors\BankConnectorAdapter;
use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Contracts\IBankIntegrationService;
use App\Domain\Banking\Services\BankAccountOpeningService;
use App\Domain\Banking\Services\BankAccountProviderFactory;
use App\Domain\Banking\Services\BankHealthMonitor;
use App\Domain\Banking\Services\BankIntegrationService;
use App\Domain\Banking\Services\BankRoutingService;
use App\Domain\Compliance\Services\KycService;
use App\Domain\Custodian\Connectors\DeutscheBankConnector;
use App\Domain\Custodian\Connectors\FlutterwaveConnector;
use App\Domain\Custodian\Connectors\PayseraConnector;
use App\Domain\Custodian\Connectors\SantanderConnector;
use Illuminate\Support\ServiceProvider;

class BankIntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register core services
        $this->app->singleton(BankHealthMonitor::class);
        $this->app->singleton(BankRoutingService::class);

        // Register bank integration service
        $this->app->singleton(
            IBankIntegrationService::class,
            function ($app) {
                $service = new BankIntegrationService(
                    $app->make(BankHealthMonitor::class),
                    $app->make(BankRoutingService::class)
                );

                // Register bank connectors
                $this->registerBankConnectors($service);

                return $service;
            }
        );

        // Bank-account opening: the provider is resolved from
        // config/banking.php via the factory. The default is the sandbox
        // adapter, which refuses to run in production; a production
        // provider requires a contracted partner bank.
        $this->app->singleton(
            AccountProviderInterface::class,
            fn ($app) => $app->make(BankAccountProviderFactory::class)->make(),
        );
        $this->app->singleton(BankAccountProviderFactory::class);
        $this->app->singleton(
            BankAccountOpeningService::class,
            fn ($app) => new BankAccountOpeningService(
                $app->make(AccountProviderInterface::class),
                $app->make(KycService::class),
            ),
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Schedule health checks
        $this->app->booted(
            function () {
                $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

                $schedule->call(
                    function () {
                        $monitor = app(BankHealthMonitor::class);
                        $monitor->checkAllBanks();
                    }
                )->everyFiveMinutes()->name('bank-health-check')->withoutOverlapping();
            }
        );
    }

    /**
     * Register all bank connectors.
     */
    private function registerBankConnectors(IBankIntegrationService $service): void
    {
        // Paysera
        if (config('services.banks.paysera.enabled', true)) {
            $payseraAdapter = new BankConnectorAdapter(
                new PayseraConnector(
                    [
                        'name'          => 'Paysera',
                        'client_id'     => config('services.banks.paysera.client_id'),
                        'client_secret' => config('services.banks.paysera.client_secret'),
                        'base_url'      => config('services.banks.paysera.base_url', 'https://bank.paysera.com/rest/v1'),
                    ]
                ),
                'PAYSERA',
                'Paysera'
            );
            $service->registerConnector('PAYSERA', $payseraAdapter);
        }

        // Deutsche Bank
        if (config('services.banks.deutsche.enabled', true)) {
            $deutscheAdapter = new BankConnectorAdapter(
                new DeutscheBankConnector(
                    [
                        'name'          => 'Deutsche Bank',
                        'client_id'     => config('services.banks.deutsche.client_id'),
                        'client_secret' => config('services.banks.deutsche.client_secret'),
                        'base_url'      => config('services.banks.deutsche.base_url', 'https://api.db.com/v2'),
                    ]
                ),
                'DEUTSCHE',
                'Deutsche Bank'
            );
            $service->registerConnector('DEUTSCHE', $deutscheAdapter);
        }

        // Santander
        if (config('services.banks.santander.enabled', true)) {
            $santanderAdapter = new BankConnectorAdapter(
                new SantanderConnector(
                    [
                        'name'          => 'Santander',
                        'client_id'     => config('services.banks.santander.client_id'),
                        'client_secret' => config('services.banks.santander.client_secret'),
                        'base_url'      => config('services.banks.santander.base_url', 'https://api.santander.com/v2'),
                    ]
                ),
                'SANTANDER',
                'Santander'
            );
            $service->registerConnector('SANTANDER', $santanderAdapter);
        }

        // Flutterwave (African fiat on/off-ramp)
        if (config('services.banks.flutterwave.enabled', false)) {
            $flutterwaveAdapter = new BankConnectorAdapter(
                new FlutterwaveConnector(
                    [
                        'name'           => 'Flutterwave',
                        'secret_key'     => config('services.banks.flutterwave.secret_key'),
                        'public_key'     => config('services.banks.flutterwave.public_key'),
                        'encryption_key' => config('services.banks.flutterwave.encryption_key'),
                        'base_url'       => 'https://api.flutterwave.com/v3',
                    ]
                ),
                'FLUTTERWAVE',
                'Flutterwave'
            );
            $service->registerConnector('FLUTTERWAVE', $flutterwaveAdapter);
        }

        // Sandbox bank (simulated account opening) — dev/test only. The
        // adapter itself also refuses to run in production; this guard is a
        // second layer so the SANDBOX connector is never registered there.
        if (config('banking.providers.sandbox.enabled', true) && ! app()->environment('production')) {
            $service->registerConnector('SANDBOX', new SandboxBankAdapter());
        }

        // Additional banks can be registered here
        // Revolut, Wise, etc.
    }
}
