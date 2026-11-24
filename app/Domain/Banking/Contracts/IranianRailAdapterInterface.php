<?php

declare(strict_types=1);

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Enums\IranianPaymentRail;
use App\Domain\Banking\Models\IranianRailTransfer;
use App\Domain\Ledger\Enums\TransactionState;
use RuntimeException;

/**
 * Minimal interface for an Iranian rail transfer.
 *
 * Only the sandbox adapter implements this today. A future production
 * adapter (blocked on official CBI/bank docs + credentials + partnership)
 * must implement the same interface — domain code never talks to a bank
 * directly.
 *
 * Amounts are integer minor units (rials for IRR) — never floats.
 */
interface IranianRailAdapterInterface
{
    public function getRail(): IranianPaymentRail;

    /**
     * True only when BOTH gates hold: this rail is enabled in config
     * (payment_rails.<rail>.enabled) AND sandbox simulation is allowed
     * (local/testing/demo with simulation explicitly enabled). The two
     * gates are independent — a disabled rail is unavailable even when
     * simulation is on.
     */
    public function isAvailable(): bool;

    /**
     * Start a transfer. Returns the local transfer row in state INITIATED.
     *
     * @throws RuntimeException when the rail is disabled, when simulation
     *         is not allowed (production), or when the idempotency key was
     *         already used (use initiateTransferIdempotent() to replay
     *         the original row instead).
     */
    public function initiateTransfer(int $amountMinor, string $idempotencyKey): IranianRailTransfer;

    /** Idempotent initiate: a replayed key returns the original transfer. */
    public function initiateTransferIdempotent(int $amountMinor, string $idempotencyKey): IranianRailTransfer;

    /** Advance / report the transfer's canonical state. */
    public function getTransferStatus(string $reference): TransactionState;

    /** Cancel a transfer that has not settled. Returns false when it cannot be cancelled. */
    public function cancelTransfer(string $reference): bool;
}
