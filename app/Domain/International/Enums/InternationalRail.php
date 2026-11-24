<?php

declare(strict_types=1);

namespace App\Domain\International\Enums;

/**
 * International rails addressable by name. No rail here is connected to
 * any real network: there is no SWIFT / SEPA / Fedwire endpoint, no
 * credential and no message format anywhere in the repository, and none
 * is invented. Every rail is DISABLED by default (config
 * `international.<rail>.enabled`, default false) and the only gateway
 * implementation — DisabledInternationalRailGateway — refuses every
 * dispatch. A production rail needs a licensed provider contract,
 * sandbox credentials and a compliance review first (see
 * docs/INTERNATIONAL_P2P_DESIGN.md §2).
 */
enum InternationalRail: string
{
    case SWIFT = 'swift';
    case SEPA = 'sepa';
    case SEPA_INSTANT = 'sepa_instant';
    case FEDWIRE = 'fedwire';
    case ACH = 'ach';
    case RTP = 'rtp';
    case FEDNOW = 'fednow';

    public function label(): string
    {
        return match ($this) {
            self::SWIFT        => 'SWIFT',
            self::SEPA         => 'SEPA',
            self::SEPA_INSTANT => 'SEPA Instant',
            self::FEDWIRE      => 'Fedwire',
            self::ACH          => 'ACH',
            self::RTP          => 'RTP',
            self::FEDNOW       => 'FedNow',
        };
    }

    /**
     * True only when the rail is explicitly enabled in config. There is
     * no config file shipped for this domain, so the default is false:
     * every rail is disabled until an operator enables it AND a provider
     * gateway is registered. Enabling the flag alone never executes a
     * transfer — the gateway still refuses without a provider.
     */
    public function isEnabled(): bool
    {
        return (bool) config("international.{$this->value}.enabled", false);
    }
}
