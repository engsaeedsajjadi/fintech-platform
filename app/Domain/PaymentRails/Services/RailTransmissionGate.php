<?php

declare(strict_types=1);

namespace App\Domain\PaymentRails\Services;

use App\Domain\PaymentRails\Exceptions\RailUnavailableException;

/**
 * Transmission gate for the US rails (FedNow, Fedwire, RTP).
 *
 * These services build ISO 20022 / network messages locally. There is
 * NO live transmission endpoint anywhere in the codebase — no FedNow
 * service-provider connection, no Fedwire endpoint, no RTP participant
 * hookup — so "sending" would only ever create a local row that looks
 * like money in flight. The gate therefore allows exactly two outcomes:
 *
 * 1. Simulation mode (non-production only, `payment_rails.simulation`
 *    enabled AND the rail's own `enabled` flag on): the instruction is
 *    recorded locally with status INITIATED and `transmitted: false`.
 *    It is a draft, not a payment.
 * 2. Anything else: {@see RailUnavailableException} — an explicit,
 *    auditable refusal. No row is created, no id is returned.
 *
 * A live rail needs a contracted provider, its official API
 * documentation, credentials, and a transmission implementation —
 * none of which exist yet.
 */
final class RailTransmissionGate
{
    public static function ensureSimulationAllowed(string $railLabel, string $configKey): void
    {
        if (! (bool) config("payment_rails.{$configKey}.enabled", false)) {
            throw new RailUnavailableException(
                "{$railLabel} is not enabled (payment_rails.{$configKey}.enabled=false). No payment was sent.",
            );
        }

        if (! self::simulationAllowed()) {
            throw new RailUnavailableException(
                "{$railLabel} has no live transmission endpoint: the payment message is built locally but cannot be sent to the network. " .
                'Enable simulation mode (payment_rails.simulation.enabled, non-production only) to record the instruction locally, ' .
                'or connect a live rail provider with official API documentation and credentials.',
            );
        }
    }

    public static function simulationAllowed(): bool
    {
        return (bool) config('payment_rails.simulation.enabled', false)
            && ! app()->environment('production');
    }
}
