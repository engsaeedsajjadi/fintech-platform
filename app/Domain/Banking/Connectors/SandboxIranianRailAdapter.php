<?php

declare(strict_types=1);

namespace App\Domain\Banking\Connectors;

use App\Domain\Banking\Contracts\IranianRailAdapterInterface;
use App\Domain\Banking\Enums\IranianPaymentRail;
use App\Domain\Banking\Models\IranianRailTransfer;
use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\Services\DemoModeGuard;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * SANDBOX-ONLY Iranian rail adapter.
 *
 * Simulates ONLY the local state machine for SATNA / PAYA / PEL /
 * card-to-card: initiated → processing → successful, with failed /
 * reversed / unknown reachable through explicit calls. It performs NO
 * network call, talks to NO bank endpoint (none exists and none is
 * invented), validates NO real account numbers or IBANs, and moves NO
 * real money. Amounts are integer minor units (rials for IRR).
 *
 * The adapter FAILS CLOSED outside local/testing/demo, and fails closed
 * whenever simulation is not explicitly enabled. Production execution is
 * disabled until official CBI / partner-bank documentation, a licensed
 * bank/PSP partnership with sandbox credentials, and a compliance /
 * sanctions review exist. A production adapter must implement
 * IranianRailAdapterInterface against the real bank API — this class must
 * never be "switched on" for production.
 */
final class SandboxIranianRailAdapter implements IranianRailAdapterInterface
{
    public function __construct(
        private readonly IranianPaymentRail $rail,
    ) {
    }

    public function getRail(): IranianPaymentRail
    {
        return $this->rail;
    }

    public function isAvailable(): bool
    {
        // Available only as a sandbox: simulation allowed AND the rail is
        // not (mis)configured as a live rail. There is no live rail.
        return DemoModeGuard::isSimulationAllowed();
    }

    public function initiateTransfer(int $amountMinor, string $idempotencyKey): IranianRailTransfer
    {
        $this->assertSandboxAllowed();

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Rail transfer amount must be a positive integer in minor units.');
        }

        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty.');
        }

        $amount = MoneyAmount::fromMinorUnits($amountMinor, 'IRR');

        return IranianRailTransfer::create([
            'rail'            => $this->rail->value,
            'reference'       => 'ir_' . Str::ulid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'amount_minor'    => $amountMinor,
            'amount_decimal'  => $amount->decimal,
            'currency'        => 'IRR',
            'state'           => TransactionState::INITIATED->value,
        ]);
    }

    public function initiateTransferIdempotent(int $amountMinor, string $idempotencyKey): IranianRailTransfer
    {
        try {
            return $this->initiateTransfer($amountMinor, $idempotencyKey);
        } catch (QueryException $e) {
            $original = IranianRailTransfer::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($original !== null) {
                return $original;
            }

            throw $e;
        }
    }

    /**
     * Sandbox state machine, advanced explicitly by the caller:
     * initiated → processing on first status check, and the caller (or a
     * test) settles it with settle() / fail() / markUnknown(). There is no
     * fake bank response and no invented settlement timing: the state only
     * moves when the local driver says so.
     */
    public function getTransferStatus(string $reference): TransactionState
    {
        $this->assertSandboxAllowed();

        $transfer = $this->findOrFail($reference);

        if ($transfer->state === TransactionState::INITIATED->value) {
            $transfer->update(['state' => TransactionState::PROCESSING->value]);
        }

        return TransactionState::from($transfer->state);
    }

    /** Sandbox-only: mark a processing transfer successful. */
    public function settle(string $reference): IranianRailTransfer
    {
        $this->assertSandboxAllowed();

        $transfer = $this->findOrFail($reference);
        $state = TransactionState::from($transfer->state);

        if ($state->isTerminal()) {
            throw new RuntimeException("Sandbox transfer {$reference} is already {$state->value}.");
        }

        $transfer->update(['state' => TransactionState::SUCCESSFUL->value]);

        return $transfer->refresh();
    }

    /** Sandbox-only: mark a transfer failed with a reason. */
    public function fail(string $reference, string $reason): IranianRailTransfer
    {
        $this->assertSandboxAllowed();

        $transfer = $this->findOrFail($reference);
        $state = TransactionState::from($transfer->state);

        if ($state->isTerminal()) {
            throw new RuntimeException("Sandbox transfer {$reference} is already {$state->value}.");
        }

        $transfer->update(['state' => TransactionState::FAILED->value, 'failure_reason' => $reason]);

        return $transfer->refresh();
    }

    /** Sandbox-only: mark a transfer unknown (e.g. simulated timeout). */
    public function markUnknown(string $reference, string $reason): IranianRailTransfer
    {
        $this->assertSandboxAllowed();

        $transfer = $this->findOrFail($reference);
        $state = TransactionState::from($transfer->state);

        if ($state->isTerminal()) {
            throw new RuntimeException("Sandbox transfer {$reference} is already {$state->value}.");
        }

        $transfer->update(['state' => TransactionState::UNKNOWN->value, 'failure_reason' => $reason]);

        return $transfer->refresh();
    }

    public function cancelTransfer(string $reference): bool
    {
        $this->assertSandboxAllowed();

        $transfer = $this->findOrFail($reference);
        $state = TransactionState::from($transfer->state);

        if ($state->isTerminal() || $state === TransactionState::SUCCESSFUL) {
            return false;
        }

        $transfer->update(['state' => TransactionState::REVERSED->value]);

        return true;
    }

    private function findOrFail(string $reference): IranianRailTransfer
    {
        return IranianRailTransfer::query()->where('reference', $reference)->firstOrFail();
    }

    private function assertSandboxAllowed(): void
    {
        DemoModeGuard::assertSimulationAllowed('iranian-rail-sandbox:' . $this->rail->value);
    }
}
