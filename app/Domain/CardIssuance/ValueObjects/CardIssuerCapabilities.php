<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\ValueObjects;

/**
 * Provider capability flags for a card issuer adapter.
 *
 * Capabilities describe what the provider behind the adapter can actually
 * do — they are derived from the adapter's implemented API surface, not from
 * marketing claims. Callers MUST check a flag before invoking the matching
 * operation; unsupported operations fail with an explicit
 * {@see \App\Domain\CardIssuance\Exceptions\UnsupportedCardOperationException}
 * instead of a fake success.
 */
final readonly class CardIssuerCapabilities
{
    public function __construct(
        public bool $supportsPhysicalCards = false,
        public bool $supportsWalletProvisioning = false,
        public bool $supportsDeliveryTracking = false,
        public bool $supportsTopUp = false,
        public bool $supportsSpendLimits = false,
        public bool $supportsActivation = false,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'supports_physical_cards'     => $this->supportsPhysicalCards,
            'supports_wallet_provisioning' => $this->supportsWalletProvisioning,
            'supports_delivery_tracking'   => $this->supportsDeliveryTracking,
            'supports_top_up'             => $this->supportsTopUp,
            'supports_spend_limits'       => $this->supportsSpendLimits,
            'supports_activation'         => $this->supportsActivation,
        ];
    }
}
