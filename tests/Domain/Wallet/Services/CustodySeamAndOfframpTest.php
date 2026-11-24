<?php

declare(strict_types=1);

use App\Domain\Custodian\Contracts\CustodyProviderInterface;
use App\Domain\Custodian\Contracts\ICustodianConnector;
use App\Domain\Ramp\Exceptions\OfframpNotAvailableException;
use App\Domain\Ramp\Services\OfframpService;

it('exposes the custody seam under one name: CustodyProviderInterface aliases ICustodianConnector', function () {
    expect(interface_exists(CustodyProviderInterface::class))->toBeTrue()
        ->and(is_subclass_of(CustodyProviderInterface::class, ICustodianConnector::class))->toBeTrue();

    $methods = get_class_methods(CustodyProviderInterface::class);
    $parentMethods = get_class_methods(ICustodianConnector::class);
    sort($methods);
    sort($parentMethods);
    // The alias adds no methods: one seam, no duplicate.
    expect($methods)->toBe($parentMethods);
});

it('off-ramp stays explicitly unavailable in v1', function () {
    $service = new OfframpService();

    expect($service->isAvailable('bridge'))->toBeFalse()
        ->and(fn () => $service->requestOfframp('bridge', ['amount' => '100']))->toThrow(OfframpNotAvailableException::class);
});
