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
use Illuminate\Support\Facades\DB;
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
 * TWO INDEPENDENT GATES, both fail-closed:
 *   1. Simulation gate (DemoModeGuard): the app environment must be
 *      local/testing/demo AND simulation explicitly enabled. This is
 *      about *where* simulated money may run.
 *   2. Rail gate (config payment_rails.<rail>.enabled): the individual
 *      rail must be enabled. This is about *which* rail may run. A rail
 *      configured as disabled can never initiate — or settle — a transfer
 *      through this adapter, even when simulation itself is allowed.
 *      The gates are checked separately so a misconfigured rail cannot
 *      ride on the simulation flag (and vice versa).
 *
 * The adapter FAILS CLOSED outside local/testing/demo, and fails closed
 * whenever simulation is not explicitly enabled. Production execution is
 * disabled until official CBI / partner-bank documentation, a licensed
 * bank/PSP partnership with sandbox credentials, and a compliance /
 * sanctions review exist. A production adapter must implement
 * IranianRailAdapterInterface against the real bank API — this class must
 * never be "switched on" for production.
 *
 * State machine (valid transitions only, enforced under a row lock):
 *   initiated/processing → successful | failed | reversed | unknown
 *   successful / failed / reversed are terminal and never move again.
 *   unknown is entered on a simulated timeout and is terminal for
 *   settlement: it is resolved only by a status query or manual review —
 *   never by retrying the money movement (settle/fail/markUnknown/cancel
 *   all refuse an unknown transfer).
 */
final class SandboxIranianRailAdapter implements IranianRailAdapterInterface
{
    /** States a transfer may leave via settle/fail/markUnknown/cancel. */
    private const TRANSITIONABLE_STATES = [
        TransactionState::INITIATED,
        TransactionState::PROCESSING,
    ];

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
        // Both gates must hold: this rail enabled AND simulation allowed.
        // A disabled rail is unavailable even when simulation is on.
        return $this->rail->isEnabled() && DemoModeGuard::isSimulationAllowed();
    }

    public function initiateTransfer(int $amountMinor, string $idempotencyKey): IranianRailTransfer
    {
        $this->assertSandboxAllowed();
        $this->assertRailEnabled();
        $this->validateInitiate($amountMinor, $idempotencyKey);

        try {
            return $this->createTransfer($amountMinor, $idempotencyKey);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = IranianRailTransfer::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    throw new RuntimeException(
                        "Idempotency key '{$idempotencyKey}' was already used for rail '{$existing->rail}'. "
                        . 'Use initiateTransferIdempotent() to replay the original transfer instead of creating a second one.',
                        0,
                        $e,
                    );
                }
            }

            throw $e;
        }
    }

    public function initiateTransferIdempotent(int $amountMinor, string $idempotencyKey): IranianRailTransfer
    {
        $this->assertSandboxAllowed();
        $this->assertRailEnabled();
        $this->validateInitiate($amountMinor, $idempotencyKey);

        // Fast path: the key already has a transfer on THIS rail.
        $existing = IranianRailTransfer::query()
            ->where('rail', $this->rail->value)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->createTransfer($amountMinor, $idempotencyKey);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost a concurrent-create race: the winner's row is committed
            // now, so re-read it instead of creating a second transfer.
            // The idempotency key is globally unique, so a winner on a
            // different rail is a client error, not a replay.
            $winner = IranianRailTransfer::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            if ($winner->rail !== $this->rail->value) {
                throw new InvalidArgumentException(
                    "Idempotency key '{$idempotencyKey}' belongs to rail '{$winner->rail}', not '{$this->rail->value}'.",
                );
            }

            return $winner;
        }
    }

    /**
     * Sandbox state machine, advanced explicitly by the caller:
     * initiated → processing on first status check, and the caller (or a
     * test) settles it with settle() / fail() / markUnknown(). There is no
     * fake bank response and no invented settlement timing: the state only
     * moves when the local driver says so. Terminal and unknown transfers
     * report their state unchanged — an unknown transfer is never
     * auto-failed or auto-settled by a status query.
     */
    public function getTransferStatus(string $reference): TransactionState
    {
        $this->assertSandboxAllowed();
        $this->assertRailEnabled();

        // Atomic: only an initiated transfer advances, and only once —
        // concurrent status checks cannot move it twice.
        IranianRailTransfer::query()
            ->where('reference', $reference)
            ->where('state', TransactionState::INITIATED->value)
            ->update(['state' => TransactionState::PROCESSING->value]);

        return TransactionState::from($this->findOrFail($reference)->state);
    }

    /** Sandbox-only: mark a processing transfer successful. */
    public function settle(string $reference): IranianRailTransfer
    {
        return $this->transition($reference, TransactionState::SUCCESSFUL);
    }

    /** Sandbox-only: mark a transfer failed with a reason. */
    public function fail(string $reference, string $reason): IranianRailTransfer
    {
        return $this->transition($reference, TransactionState::FAILED, $reason);
    }

    /** Sandbox-only: mark a transfer unknown (e.g. simulated timeout). */
    public function markUnknown(string $reference, string $reason): IranianRailTransfer
    {
        return $this->transition($reference, TransactionState::UNKNOWN, $reason);
    }

    public function cancelTransfer(string $reference): bool
    {
        $this->assertSandboxAllowed();
        $this->assertRailEnabled();

        return DB::transaction(function () use ($reference): bool {
            $transfer = IranianRailTransfer::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            $state = TransactionState::from($transfer->state);

            if (! in_array($state, self::TRANSITIONABLE_STATES, true)) {
                return false;
            }

            $transfer->update(['state' => TransactionState::REVERSED->value]);

            return true;
        });
    }

    /**
     * The single transition path for settle/fail/markUnknown: lock the
     * row, re-read its state, and move it only from initiated/processing.
     * Two concurrent settles serialise on the lock, so the second one
     * sees the terminal state and fails instead of settling twice.
     */
    private function transition(string $reference, TransactionState $to, ?string $failureReason = null): IranianRailTransfer
    {
        $this->assertSandboxAllowed();
        $this->assertRailEnabled();

        return DB::transaction(function () use ($reference, $to, $failureReason): IranianRailTransfer {
            $transfer = IranianRailTransfer::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            $state = TransactionState::from($transfer->state);

            if (! in_array($state, self::TRANSITIONABLE_STATES, true)) {
                throw new RuntimeException(
                    "Sandbox transfer {$reference} is {$state->value} and cannot move to {$to->value}. "
                    . ($state === TransactionState::UNKNOWN
                        ? 'An unknown transfer is resolved by a status query or manual review, never by retrying the movement.'
                        : 'Terminal transfers never transition again.'),
                );
            }

            $attributes = ['state' => $to->value];
            if ($failureReason !== null) {
                $attributes['failure_reason'] = $failureReason;
            }

            $transfer->update($attributes);

            return $transfer->refresh();
        });
    }

    private function createTransfer(int $amountMinor, string $idempotencyKey): IranianRailTransfer
    {
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

    private function validateInitiate(int $amountMinor, string $idempotencyKey): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Rail transfer amount must be a positive integer in minor units.');
        }

        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty.');
        }

        if (strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('Idempotency key must be at most 255 characters.');
        }
    }

    private function findOrFail(string $reference): IranianRailTransfer
    {
        return IranianRailTransfer::query()->where('reference', $reference)->firstOrFail();
    }

    private function assertSandboxAllowed(): void
    {
        DemoModeGuard::assertSimulationAllowed('iranian-rail-sandbox:' . $this->rail->value);
    }

    private function assertRailEnabled(): void
    {
        if (! $this->rail->isEnabled()) {
            throw new RuntimeException(
                "Iranian rail '{$this->rail->value}' is disabled "
                . "(payment_rails.{$this->rail->value}.enabled=false); transfers are not allowed "
                . 'while the rail is disabled. Enable the rail explicitly to use the sandbox adapter.',
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 covers SQLite, MySQL and Postgres unique violations.
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
