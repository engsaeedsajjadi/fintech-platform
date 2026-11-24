<?php

declare(strict_types=1);

use App\Domain\International\Enums\InternationalRail;
use App\Domain\International\Exceptions\InternationalRailNotAvailableException;
use App\Domain\International\Services\DisabledInternationalRailGateway;

it('has every international rail disabled by default', function (): void {
    foreach (InternationalRail::cases() as $rail) {
        expect($rail->isEnabled())->toBeFalse();
    }
});

it('refuses dispatch, status and cancel on every rail', function (): void {
    $gateway = new DisabledInternationalRailGateway();

    foreach (InternationalRail::cases() as $rail) {
        expect(fn () => $gateway->dispatch('transfer-1', $rail, []))
            ->toThrow(InternationalRailNotAvailableException::class, 'not available');

        expect(fn () => $gateway->status('provider-ref', $rail))
            ->toThrow(InternationalRailNotAvailableException::class, 'not available');

        expect(fn () => $gateway->cancel('provider-ref', $rail))
            ->toThrow(InternationalRailNotAvailableException::class, 'not available');
    }
});

it('names the rail in the refusal so the failure is never mistaken for transient', function (): void {
    $gateway = new DisabledInternationalRailGateway();

    try {
        $gateway->dispatch('transfer-1', InternationalRail::SWIFT, []);
        $this->fail('Expected InternationalRailNotAvailableException.');
    } catch (InternationalRailNotAvailableException $e) {
        expect($e->getMessage())->toContain('swift')
            ->and($e->getMessage())->toContain('no provider is registered');
    }
});
