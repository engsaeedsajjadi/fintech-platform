<?php

declare(strict_types=1);

namespace App\Domain\International\Contracts;

use App\Domain\International\Enums\InternationalRail;

/**
 * Execution gateway for an international rail.
 *
 * This is the ONLY seam through which money could leave the platform
 * to a foreign bank. Today the only implementation is
 * DisabledInternationalRailGateway, which refuses every call: no
 * provider is registered, no endpoint exists, no credential exists.
 * A production implementation must be a separate adapter behind this
 * interface, built against a real provider contract (licensed partner,
 * sandbox credentials, compliance review) — domain code never talks to
 * a bank directly.
 */
interface InternationalRailGatewayInterface
{
    /**
     * Dispatch a transfer to the rail. Returns the provider reference.
     *
     * @param array<string, mixed> $instruction
     * @throws \App\Domain\International\Exceptions\InternationalRailNotAvailableException
     *         when the rail has no registered provider (always, today).
     */
    public function dispatch(string $transferId, InternationalRail $rail, array $instruction): string;

    /**
     * Query the provider status of a dispatched transfer.
     *
     * @throws \App\Domain\International\Exceptions\InternationalRailNotAvailableException
     */
    public function status(string $providerReference, InternationalRail $rail): string;

    /**
     * Cancel a dispatched transfer.
     *
     * @throws \App\Domain\International\Exceptions\InternationalRailNotAvailableException
     */
    public function cancel(string $providerReference, InternationalRail $rail): bool;
}
