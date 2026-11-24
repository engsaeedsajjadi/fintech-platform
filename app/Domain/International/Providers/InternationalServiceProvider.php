<?php

declare(strict_types=1);

namespace App\Domain\International\Providers;

use App\Domain\International\Contracts\ExchangeRateProviderInterface;
use App\Domain\International\Contracts\InternationalRailGatewayInterface;
use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Services\DisabledInternationalRailGateway;
use App\Domain\International\Services\ManualReviewScreeningAdapter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Bindings for the International domain's provider seams.
 *
 * Registered in bootstrap/providers.php. The default bindings remain
 * fail-closed until a real, approved provider is configured. This is the
 * single integration point for a future rail gateway or screening adapter.
 *
 * - InternationalRailGatewayInterface → DisabledInternationalRailGateway
 *   (refuses every dispatch; no provider is connected).
 * - InternationalScreeningInterface → ManualReviewScreeningAdapter
 *   (every check needs a compliance-officer decision).
 * - ExchangeRateProviderInterface is deliberately NOT bound: there is
 *   no FX feed, and quotations are created only with operator-supplied
 *   rates. Bind a licensed feed here when one exists.
 */
final class InternationalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InternationalRailGatewayInterface::class,
            DisabledInternationalRailGateway::class,
        );

        $this->app->bind(
            InternationalScreeningInterface::class,
            ManualReviewScreeningAdapter::class,
        );

        // ExchangeRateProviderInterface: intentionally unbound (no FX feed).
        $this->app->bindIf(
            ExchangeRateProviderInterface::class,
            fn () => throw new RuntimeException('No exchange-rate provider is registered. Quotations require an operator-supplied rate.'),
        );
    }
}
