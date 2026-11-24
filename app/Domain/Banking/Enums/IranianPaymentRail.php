<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

/**
 * Iranian payment rails — RESERVED enum cases only.
 *
 * There is no live Iranian rail in this codebase: no CBI or bank API
 * documentation, no endpoint, no credential, and no message format exists
 * anywhere in the repository, and none is invented here. These cases exist
 * so the rails can be addressed by name (config, routing, reporting) and
 * so the sandbox adapter has a typed rail to work with.
 *
 * Production execution is DISABLED and stays disabled until official
 * CBI / partner-bank documentation, a licensed bank/PSP partnership with
 * sandbox credentials, and a compliance/sanctions review all exist.
 * See config/payment_rails.php and SandboxIranianRailAdapter.
 */
enum IranianPaymentRail: string
{
    case SATNA = 'satna';
    case PAYA = 'paya';
    case PEL = 'pel';
    case CARD_TO_CARD = 'card_to_card';

    public function label(): string
    {
        return match ($this) {
            self::SATNA        => 'SATNA',
            self::PAYA         => 'PAYA',
            self::PEL          => 'PEL (پل)',
            self::CARD_TO_CARD => 'Card-to-card (کارت به کارت)',
        };
    }

    /** True when the rail is enabled in config — false by default for all. */
    public function isEnabled(): bool
    {
        return (bool) config("payment_rails.{$this->value}.enabled", false);
    }
}
