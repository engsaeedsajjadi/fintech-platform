<?php

declare(strict_types=1);

use App\Domain\PaymentRails\Enums\RailStatus;
use App\Domain\PaymentRails\Exceptions\RailUnavailableException;
use App\Domain\PaymentRails\Services\FedNowService;
use App\Domain\PaymentRails\Services\FedwireService;
use App\Domain\PaymentRails\Services\PaymentRailRouter;
use App\Domain\PaymentRails\Services\RtpService;
use App\Models\User;

uses(Tests\TestCase::class);

function railUser(): User
{
    return User::factory()->create();
}

it('fednow refuses to send when the rail is disabled — no fake in-flight payment', function (): void {
    config(['payment_rails.fednow.enabled' => false]);

    app(FedNowService::class)->sendInstantPayment(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        'DE89370400440532013000',
        'COBADEFFXXX',
    );
})->throws(RailUnavailableException::class, 'FedNow');

it('fedwire refuses to send when the rail is disabled', function (): void {
    config(['payment_rails.fedwire.enabled' => false]);

    app(FedwireService::class)->sendTransfer(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        '123456789',
        '021000021',
    );
})->throws(RailUnavailableException::class, 'Fedwire');

it('rtp refuses to send when the rail is disabled', function (): void {
    config(['payment_rails.rtp.enabled' => false]);

    app(RtpService::class)->sendPayment(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        '123456789',
        '021000021',
    );
})->throws(RailUnavailableException::class, 'RTP');

it('fednow in simulation mode records the instruction as initiated, never processing, and marks it untransmitted', function (): void {
    config([
        'payment_rails.fednow.enabled'        => true,
        'payment_rails.fednow.participant_id' => 'FNAEGISUS',
        'payment_rails.simulation.enabled'    => true,
    ]);

    $result = app(FedNowService::class)->sendInstantPayment(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        'DE89370400440532013000',
        'COBADEFFXXX',
    );

    expect($result['status'])->toBe(RailStatus::INITIATED->value)
        ->and($result['transmitted'])->toBeFalse()
        ->and($result['simulation'])->toBeTrue();

    $stored = App\Domain\PaymentRails\Models\PaymentRailTransaction::findOrFail($result['transaction_id']);
    expect($stored->status)->toBe(RailStatus::INITIATED)
        ->and($stored->metadata['transmitted'])->toBeFalse()
        ->and($stored->metadata['simulation'])->toBeTrue();
});

it('fedwire in simulation mode records the instruction as initiated and untransmitted', function (): void {
    config([
        'payment_rails.fedwire.enabled'    => true,
        'payment_rails.simulation.enabled' => true,
    ]);

    $result = app(FedwireService::class)->sendTransfer(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        '123456789',
        '021000021',
    );

    expect($result['status'])->toBe(RailStatus::INITIATED->value)
        ->and($result['transmitted'])->toBeFalse();
});

it('rtp in simulation mode records the instruction as initiated and untransmitted', function (): void {
    config([
        'payment_rails.rtp.enabled'        => true,
        'payment_rails.simulation.enabled' => true,
    ]);

    $result = app(RtpService::class)->sendPayment(
        railUser()->id,
        '10.00',
        'USD',
        'Jane Doe',
        '123456789',
        '021000021',
    );

    expect($result['status'])->toBe(RailStatus::INITIATED->value)
        ->and($result['transmitted'])->toBeFalse();
});

it('rtp rejects an amount above the maximum using exact decimal comparison, not float rounding', function (): void {
    config([
        'payment_rails.rtp.enabled'        => true,
        'payment_rails.rtp.max_amount'     => 100000000,
        'payment_rails.simulation.enabled' => true,
    ]);

    // 1,000,000.01 USD = 100,000,001 cents > 100,000,000 max. A float
    // comparison must not let rounding smuggle this through.
    app(RtpService::class)->sendPayment(
        railUser()->id,
        '1000000.01',
        'USD',
        'Jane Doe',
        '123456789',
        '021000021',
    );
})->throws(InvalidArgumentException::class, 'exceeds maximum');

it('router fails closed when no rail is enabled instead of dispatching a fake payment', function (): void {
    config([
        'payment_rails.ach.enabled'        => false,
        'payment_rails.fedwire.enabled'    => false,
        'payment_rails.rtp.enabled'        => false,
        'payment_rails.fednow.enabled'     => false,
        'payment_rails.simulation.enabled' => false,
    ]);

    app(PaymentRailRouter::class)->route(
        railUser()->id,
        '10.00',
        'USD',
        'US',
        'normal',
        ['name' => 'Jane Doe', 'account_number' => '123456789', 'routing_number' => '021000021'],
    );
})->throws(RailUnavailableException::class);

it('router lists only enabled rails as available', function (): void {
    config([
        'payment_rails.ach.enabled'     => true,
        'payment_rails.fedwire.enabled' => false,
        'payment_rails.rtp.enabled'     => false,
        'payment_rails.fednow.enabled'  => false,
    ]);

    $available = app(PaymentRailRouter::class)->getAvailableRails('US');

    expect($available)->toBe(['ach']);
});
