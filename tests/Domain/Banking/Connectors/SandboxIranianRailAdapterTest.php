<?php

declare(strict_types=1);

use App\Domain\Banking\Connectors\SandboxIranianRailAdapter;
use App\Domain\Banking\Enums\IranianPaymentRail;
use App\Domain\Banking\Models\IranianRailTransfer;
use App\Domain\Ledger\Enums\TransactionState;

beforeEach(function (): void {
    // Sandbox simulation on (testing env + direct funding default on).
    // SATNA is the rail under test, so it is enabled; the other rails
    // stay disabled to prove the rail gate is per-rail.
    config([
        'payment_rails.simulation.enabled'   => true,
        'payment_rails.satna.enabled'        => true,
        'payment_rails.paya.enabled'         => false,
        'payment_rails.pel.enabled'          => false,
        'payment_rails.card_to_card.enabled' => false,
    ]);

    $this->adapter = new SandboxIranianRailAdapter(IranianPaymentRail::SATNA);
});

it('has reserved rails that are all disabled by default', function (): void {
    config([
        'payment_rails.satna.enabled'        => false,
        'payment_rails.paya.enabled'         => false,
        'payment_rails.pel.enabled'          => false,
        'payment_rails.card_to_card.enabled' => false,
    ]);

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
        ->and(IranianRailTransfer::count())->toBe(1);
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

// ---------------------------------------------------------------------------
// 4.3 hardening: rail enablement is enforced separately from simulation.
// ---------------------------------------------------------------------------

it('refuses to initiate a transfer on a disabled rail even when simulation is allowed', function (): void {
    $paya = new SandboxIranianRailAdapter(IranianPaymentRail::PAYA);

    expect($paya->isAvailable())->toBeFalse();

    expect(fn () => $paya->initiateTransfer(100, 'ir-key-paya-disabled'))
        ->toThrow(RuntimeException::class, "rail 'paya' is disabled");

    expect(IranianRailTransfer::where('idempotency_key', 'ir-key-paya-disabled')->count())->toBe(0);
});

it('reports availability only when the rail is enabled AND simulation is allowed', function (): void {
    // Rail on + simulation on.
    expect($this->adapter->isAvailable())->toBeTrue();

    // Rail off + simulation on.
    config(['payment_rails.satna.enabled' => false]);
    expect($this->adapter->isAvailable())->toBeFalse();

    // Rail on + simulation off.
    config([
        'payment_rails.satna.enabled'      => true,
        'payment_rails.simulation.enabled' => false,
        'ledger.direct_funding_enabled'    => false,
    ]);
    expect($this->adapter->isAvailable())->toBeFalse();
});

it('blocks settle, fail, unknown, cancel and status checks while the rail is disabled', function (): void {
    $transfer = $this->adapter->initiateTransfer(100, 'ir-key-gate');

    config(['payment_rails.satna.enabled' => false]);

    expect($this->adapter->isAvailable())->toBeFalse()
        ->and(fn () => $this->adapter->getTransferStatus($transfer->reference))
        ->toThrow(RuntimeException::class, 'disabled')
        ->and(fn () => $this->adapter->settle($transfer->reference))
        ->toThrow(RuntimeException::class, 'disabled')
        ->and(fn () => $this->adapter->fail($transfer->reference, 'x'))
        ->toThrow(RuntimeException::class, 'disabled')
        ->and(fn () => $this->adapter->markUnknown($transfer->reference, 'x'))
        ->toThrow(RuntimeException::class, 'disabled')
        ->and(fn () => $this->adapter->cancelTransfer($transfer->reference))
        ->toThrow(RuntimeException::class, 'disabled');

    // The transfer itself is untouched by the refused operations.
    expect($transfer->refresh()->state)->toBe(TransactionState::INITIATED->value);
});

it('refuses to settle a terminal transfer again', function (): void {
    $transfer = $this->adapter->initiateTransfer(100, 'ir-key-terminal');
    $this->adapter->settle($transfer->reference);

    expect(fn () => $this->adapter->settle($transfer->reference))
        ->toThrow(RuntimeException::class, 'is successful and cannot move to successful')
        ->and(fn () => $this->adapter->fail($transfer->reference, 'late failure'))
        ->toThrow(RuntimeException::class, 'is successful and cannot move to failed')
        ->and(fn () => $this->adapter->markUnknown($transfer->reference, 'late timeout'))
        ->toThrow(RuntimeException::class, 'is successful and cannot move to unknown');

    $failed = $this->adapter->initiateTransfer(100, 'ir-key-terminal-fail');
    $this->adapter->fail($failed->reference, 'failed first');

    expect(fn () => $this->adapter->settle($failed->reference))
        ->toThrow(RuntimeException::class, 'is failed and cannot move to successful');

    $reversed = $this->adapter->initiateTransfer(100, 'ir-key-terminal-rev');
    $this->adapter->cancelTransfer($reversed->reference);

    expect(fn () => $this->adapter->settle($reversed->reference))
        ->toThrow(RuntimeException::class, 'is reversed and cannot move to successful');
});

it('keeps an unknown transfer unknown: no settle, fail, re-mark or cancel, no auto-recovery', function (): void {
    $transfer = $this->adapter->initiateTransfer(100, 'ir-key-timeout');
    $this->adapter->markUnknown($transfer->reference, 'simulated timeout');

    expect($transfer->refresh()->state)->toBe(TransactionState::UNKNOWN->value)
        ->and($transfer->refresh()->failure_reason)->toBe('simulated timeout');

    // A status query reports unknown and never auto-fails or auto-settles.
    expect($this->adapter->getTransferStatus($transfer->reference))->toBe(TransactionState::UNKNOWN)
        ->and($transfer->refresh()->state)->toBe(TransactionState::UNKNOWN->value);

    expect(fn () => $this->adapter->settle($transfer->reference))
        ->toThrow(RuntimeException::class, 'is unknown')
        ->and(fn () => $this->adapter->fail($transfer->reference, 'give up'))
        ->toThrow(RuntimeException::class, 'is unknown')
        ->and(fn () => $this->adapter->markUnknown($transfer->reference, 'again'))
        ->toThrow(RuntimeException::class, 'is unknown');

    expect($this->adapter->cancelTransfer($transfer->reference))->toBeFalse();
    expect($transfer->refresh()->state)->toBe(TransactionState::UNKNOWN->value);
});

it('runs every valid transition of the sandbox state machine', function (): void {
    // initiated -> processing -> successful
    $a = $this->adapter->initiateTransfer(100, 'ir-key-t1');
    expect($this->adapter->getTransferStatus($a->reference))->toBe(TransactionState::PROCESSING);
    expect($this->adapter->settle($a->reference)->state)->toBe(TransactionState::SUCCESSFUL->value);

    // initiated -> failed
    $b = $this->adapter->initiateTransfer(100, 'ir-key-t2');
    expect($this->adapter->fail($b->reference, 'no funds')->state)->toBe(TransactionState::FAILED->value);

    // processing -> failed
    $c = $this->adapter->initiateTransfer(100, 'ir-key-t3');
    $this->adapter->getTransferStatus($c->reference);
    expect($this->adapter->fail($c->reference, 'rejected')->state)->toBe(TransactionState::FAILED->value);

    // processing -> reversed
    $d = $this->adapter->initiateTransfer(100, 'ir-key-t4');
    $this->adapter->getTransferStatus($d->reference);
    expect($this->adapter->cancelTransfer($d->reference))->toBeTrue();
    expect($d->refresh()->state)->toBe(TransactionState::REVERSED->value);

    // A failed transfer cannot be cancelled either.
    expect($this->adapter->cancelTransfer($b->reference))->toBeFalse();
});

it('rejects invalid initiate arguments', function (): void {
    expect(fn () => $this->adapter->initiateTransfer(0, 'ir-key-zero'))
        ->toThrow(InvalidArgumentException::class, 'positive integer')
        ->and(fn () => $this->adapter->initiateTransfer(-5, 'ir-key-neg'))
        ->toThrow(InvalidArgumentException::class, 'positive integer')
        ->and(fn () => $this->adapter->initiateTransfer(100, '   '))
        ->toThrow(InvalidArgumentException::class, 'must not be empty')
        ->and(fn () => $this->adapter->initiateTransfer(100, str_repeat('k', 256)))
        ->toThrow(InvalidArgumentException::class, 'at most 255 characters');

    expect(IranianRailTransfer::count())->toBe(0);
});

it('reports a clear error when initiateTransfer reuses an idempotency key', function (): void {
    $this->adapter->initiateTransfer(100000, 'ir-key-reuse');

    expect(fn () => $this->adapter->initiateTransfer(100000, 'ir-key-reuse'))
        ->toThrow(RuntimeException::class, 'already used');

    expect(IranianRailTransfer::count())->toBe(1);
});

it('refuses an idempotency key that belongs to another rail', function (): void {
    $this->adapter->initiateTransfer(100000, 'ir-key-cross-rail');

    config(['payment_rails.paya.enabled' => true]);
    $paya = new SandboxIranianRailAdapter(IranianPaymentRail::PAYA);

    expect(fn () => $paya->initiateTransferIdempotent(100000, 'ir-key-cross-rail'))
        ->toThrow(InvalidArgumentException::class, "belongs to rail 'satna'");

    expect(IranianRailTransfer::count())->toBe(1);
});
