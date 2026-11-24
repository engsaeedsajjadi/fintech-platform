<?php

use App\Domain\Asset\Services\TradingPairService;

it('seeds trading pairs from config and resolves them', function (): void {
    $service = app(TradingPairService::class);

    $pair = $service->find('BTC', 'USDT');

    expect($pair)->not->toBeNull()
        ->and($pair->symbol)->toBe('BTC/USDT')
        ->and($pair->isTradeable())->toBeTrue()
        ->and($service->isTradeable('BTC', 'USDT'))->toBeTrue();
});

it('returns null for pairs that are not configured', function (): void {
    $service = app(TradingPairService::class);

    expect($service->find('DOGE', 'USDT'))->toBeNull()
        ->and($service->isTradeable('DOGE', 'USDT'))->toBeFalse();
});

it('reports a halted pair as not tradeable', function (): void {
    $service = app(TradingPairService::class);

    $pair = $service->find('BTC', 'USDT');
    $pair->update(['halted' => true]);

    expect($service->isHalted('BTC', 'USDT'))->toBeTrue()
        ->and($service->isTradeable('BTC', 'USDT'))->toBeFalse();
});
