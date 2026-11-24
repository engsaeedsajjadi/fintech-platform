<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Contracts\InternationalRailGatewayInterface;
use App\Domain\International\Enums\InternationalRail;
use App\Domain\International\Exceptions\InternationalRailNotAvailableException;

/**
 * The ONLY rail gateway implementation in this codebase — and it refuses
 * everything.
 *
 * There is no SWIFT / SEPA / Fedwire / ACH / RTP / FedNow provider
 * registered, no endpoint, no credential and no message format. Every
 * dispatch, status query and cancel throws
 * InternationalRailNotAvailableException (fail-closed, with a clear
 * message). A transfer can therefore never leave the platform through
 * this gateway; external settlement is recorded only by an operator
 * from a real provider confirmation (see
 * InternationalTransferService::settle()).
 */
final class DisabledInternationalRailGateway implements InternationalRailGatewayInterface
{
    /** @param array<string, mixed> $instruction */
    public function dispatch(string $transferId, InternationalRail $rail, array $instruction): string
    {
        throw InternationalRailNotAvailableException::forRail($rail);
    }

    public function status(string $providerReference, InternationalRail $rail): string
    {
        throw InternationalRailNotAvailableException::forRail($rail);
    }

    public function cancel(string $providerReference, InternationalRail $rail): bool
    {
        throw InternationalRailNotAvailableException::forRail($rail);
    }
}
