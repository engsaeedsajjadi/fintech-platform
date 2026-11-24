<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Contracts;

/**
 * CustodyProviderInterface — target name for the existing seam
 * `ICustodianConnector` (TARGET_ARCHITECTURE §4.6). This interface is an
 * alias: it adds no methods, so there is exactly one custody seam and no
 * parallel duplicate. New custody code should type-hint this name;
 * existing connectors keep implementing ICustodianConnector.
 *
 * Target additions for a later phase (idempotent transfer, settlement
 * status) belong here once a licensed custodian is contracted — they are
 * not added now because no connector implements them yet.
 */
interface CustodyProviderInterface extends ICustodianConnector
{
}
