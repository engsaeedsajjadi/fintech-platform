<?php

declare(strict_types=1);

use App\Domain\Ledger\Services\DemoModeGuard;

it('allows simulation in the testing environment with direct funding on', function (): void {
    config(['ledger.direct_funding_enabled' => true]);

    expect(DemoModeGuard::isSimulationAllowed())->toBeTrue();

    // Does not throw.
    DemoModeGuard::assertSimulationAllowed('test-feature');
    expect(true)->toBeTrue();
});

it('fails closed in production, whatever the flags say', function (): void {
    config([
        'ledger.direct_funding_enabled'    => true,
        'payment_rails.simulation.enabled' => true,
    ]);

    app()->detectEnvironment(fn () => 'production');

    try {
        expect(DemoModeGuard::isSimulationAllowed())->toBeFalse()
            ->and(fn () => DemoModeGuard::assertSimulationAllowed('test-feature'))
            ->toThrow(RuntimeException::class, 'not allowed in environment');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('fails closed when simulation is explicitly disabled', function (): void {
    config([
        'ledger.direct_funding_enabled'    => false,
        'payment_rails.simulation.enabled' => false,
    ]);

    expect(DemoModeGuard::isSimulationAllowed())->toBeFalse()
        ->and(fn () => DemoModeGuard::assertSimulationAllowed('test-feature'))
        ->toThrow(RuntimeException::class, 'not allowed');
});
