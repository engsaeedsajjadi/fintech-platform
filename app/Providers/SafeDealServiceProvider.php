<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\SafeDeal\Contracts\SafeDealTradeRepositoryInterface;
use App\Domain\SafeDeal\Policies\SafeDealTradePolicy;
use App\Domain\SafeDeal\Repositories\SafeDealTradeRepository;
use App\Domain\SafeDeal\Services\CounterpartyVerificationService;
use App\Domain\SafeDeal\Services\SafeDealService;
use App\Domain\SafeDeal\Services\SafeDealSettlementService;
use App\Domain\SafeDeal\Services\TradeRiskAssessmentService;
use App\Domain\SafeDeal\Workflows\SafeDealTradeWorkflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the SafeDeal domain.
 *
 * Binds the repository contract, the lifecycle services and the workflow.
 * Ledger access stays inside SafeDealSettlementService via the already
 * bound LedgerService — this provider adds no new ledger path.
 */
class SafeDealServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SafeDealTradeRepositoryInterface::class, SafeDealTradeRepository::class);
        $this->app->bind(SafeDealTradeRepository::class, SafeDealTradeRepository::class);

        $this->app->singleton(CounterpartyVerificationService::class);
        $this->app->singleton(TradeRiskAssessmentService::class);
        $this->app->singleton(SafeDealSettlementService::class);
        $this->app->singleton(SafeDealService::class);
        $this->app->singleton(SafeDealTradeWorkflow::class);
    }

    public function boot(): void
    {
        Gate::policy(\App\Domain\SafeDeal\Models\SafeDealTrade::class, SafeDealTradePolicy::class);
    }
}
