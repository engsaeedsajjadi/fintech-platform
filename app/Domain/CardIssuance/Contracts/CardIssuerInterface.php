<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Contracts;

use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\ValueObjects\CardIssuerCapabilities;
use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\ProvisioningData;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;

/**
 * Interface for card issuer adapters (Marqeta, Lithic, Stripe Issuing).
 */
interface CardIssuerInterface
{
    /**
     * Capability flags for this issuer — what the provider behind the
     * adapter can actually do. Callers must check the flag before invoking
     * the matching operation; unsupported operations throw
     * UnsupportedCardOperationException instead of succeeding silently.
     */
    public function getCapabilities(): CardIssuerCapabilities;

    /**
     * Activate a card that was issued in a pending state.
     *
     * Returns false when the provider reports the card is not (yet) active;
     * throws UnsupportedCardOperationException when the provider has no
     * activation call (activation then happens via webhook / status sync).
     */
    public function activateCard(string $cardToken): bool;

    /**
     * Create a new virtual card for a user.
     *
     * @param array<string, mixed> $metadata
     */
    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard;

    /**
     * Get provisioning data for Apple Pay / Google Pay.
     *
     * @param array<string> $certificates
     * @return ProvisioningData Data to pass directly to native wallet APIs
     */
    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = []
    ): ProvisioningData;

    /**
     * Freeze a card (temporary block).
     */
    public function freezeCard(string $cardToken): bool;

    /**
     * Unfreeze a previously frozen card.
     */
    public function unfreezeCard(string $cardToken): bool;

    /**
     * Permanently cancel a card.
     */
    public function cancelCard(string $cardToken, string $reason): bool;

    /**
     * Get card details by token.
     */
    public function getCard(string $cardToken): ?VirtualCard;

    /**
     * List all cards for a given user.
     *
     * @return array<VirtualCard>
     */
    public function listUserCards(string $userId): array;

    /**
     * Get transaction history for a card.
     *
     * @return array{transactions: array<CardTransaction>, next_cursor: string|null}
     */
    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array;

    /**
     * Get the issuer name for identification.
     */
    public function getName(): string;
}
