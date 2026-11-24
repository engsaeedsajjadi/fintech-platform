<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * Fail-closed guard for simulated / demo money paths.
 *
 * Simulated money movement (demo gateways, sandbox rail adapters, direct
 * ledger funding) may run ONLY when every condition holds:
 *   - the application environment is local, testing, or demo, AND
 *   - the simulation feature is explicitly enabled in config.
 *
 * Production (or any other environment) fails closed with an exception.
 * There is no opt-out flag that re-enables simulation in production: a
 * production-mode demo must use a real provider adapter instead.
 */
final class DemoModeGuard
{
    /** @var list<string> */
    private const SIMULATION_ENVIRONMENTS = ['local', 'testing', 'demo'];

    /**
     * Throw unless simulated money movement is allowed right now.
     *
     * @throws RuntimeException when simulation is not allowed.
     */
    public static function assertSimulationAllowed(string $feature): void
    {
        if (! self::isSimulationAllowed()) {
            throw new RuntimeException(
                "Simulated money path '{$feature}' is not allowed in environment '" . App::environment() . "'. " .
                'Simulation runs only in local/testing/demo with simulation explicitly enabled; ' .
                'production requires a real provider adapter.'
            );
        }
    }

    public static function isSimulationAllowed(): bool
    {
        if (! in_array(App::environment(), self::SIMULATION_ENVIRONMENTS, true)) {
            return false;
        }

        return (bool) config('payment_rails.simulation.enabled', false)
            || (bool) config('ledger.direct_funding_enabled', false);
    }
}
