<?php

declare(strict_types=1);

use App\Domain\Banking\Connectors\SandboxIranianRailAdapter;
use App\Domain\Banking\Enums\IranianPaymentRail;
use App\Domain\Ledger\Enums\TransactionState;

beforeEach(function (): void {
    // Sandbox simulation on (testing env + direct funding default on).
    config([
        'payment_rails.simulation.enabled'   => true,
        'payment_rails.satna.enabled'        => false,
        'payment_rails.paya.enabled'         => false,
        'payment_rails.pel.enabled'          => false,
        'payment_rails.card_to_card.enabled' => false,
    ]);

    $this->adapter = new SandboxIranianRailAdapter(IranianPaymentRail::SATNA);
});

it('has reserved rails that are all disabled by default', function (): void {
    expect(IranianPaymentRail::SATNA->isEnabled())->toBeFalse()
        ->and(IranianPaymentRail::PAYA->isEnabled())->toBeFalse()
        ->and(IranianPaymentRail::PEL->isEnabled())->toBeFalse()
        ->and(IranianPaymentRail::CARD_TO_CARD->isEnabled())->toBeFalse();
});

it('runs the local sandbox state machine without any bank endpoint', function (): void {
    $transfer = $this->adapter->initiateTransfer(1500000, 'ir-key-1');

    expect($transfer->rail)->toBe('satna')
        ->and($transfer->state)->toBe(TransactionState::INITIATED->value)
        ->and($transfer->amount_minor)->toBe(1500000)
        ->and($transfer->currency)->toBe('IRR')
        ->and($transfer->reference)->toStartWith('ir_');

    expect($this->adapter->getTransferStatus($transfer->reference))
        ->toBe(TransactionState::PROCESSING);

    $settled = $this->adapter->settle($transfer->reference);

    expect($settled->state)->toBe(TransactionState::SUCCESSFUL->value);
});

it('returns the original transfer on a duplicate idempotency key', function (): void {
    $first = $this->adapter->initiateTransferIdempotent(100000, 'ir-key-dup');
    $second = $this->adapter->initiateTransferIdempotent(999999, 'ir-key-dup');

    expect($second->id)->toBe($first->id)
        ->and($second->amount_minor)->toBe(100000)
        ->and(App\Domain\Banking\Models\IranianRailTransfer::count())->toBe(1);
});

it('supports failure, unknown and cancel transitions in the sandbox', function (): void {
    $failed = $this->adapter->initiateTransfer(100, 'ir-key-fail');
    $this->adapter->fail($failed->reference, 'sandbox failure');

    expect($this->adapter->getTransferStatus($failed->reference))->toBe(TransactionState::FAILED);

    $unknown = $this->adapter->initiateTransfer(100, 'ir-key-unknown');
    $this->adapter->markUnknown($unknown->reference, 'simulated timeout');

    expect($this->adapter->getTransferStatus($unknown->reference))->toBe(TransactionState::UNKNOWN);

    $cancellable = $this->adapter->initiateTransfer(100, 'ir-key-cancel');

    expect($this->adapter->cancelTransfer($cancellable->reference))->toBeTrue();

    $settled = $this->adapter->initiateTransfer(100, 'ir-key-settled');
    $this->adapter->settle($settled->reference);

    expect($this->adapter->cancelTransfer($settled->reference))->toBeFalse();
});

it('fails closed in production', function (): void {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => $this->adapter->initiateTransfer(100, 'ir-key-prod'))
            ->toThrow(RuntimeException::class, 'not allowed in environment');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('fails closed when simulation is explicitly disabled', function (): void {
    config([
        'payment_rails.simulation.enabled' => false,
        'ledger.direct_funding_enabled'    => false,
    ]);

    expect($this->adapter->isAvailable())->toBeFalse()
        ->and(fn () => $this->adapter->initiateTransfer(100, 'ir-key-off'))
        ->toThrow(RuntimeException::class, 'not allowed');
});
