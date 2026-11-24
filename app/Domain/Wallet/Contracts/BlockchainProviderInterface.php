<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

/**
 * BlockchainProviderInterface — the consolidated chain-access seam
 * (TARGET_ARCHITECTURE §4.7). Chain reads/writes go through this
 * interface; signing stays in KeyManagement (HSM) and only signed
 * payloads cross this boundary. This is distinct from the lower-level
 * `BlockchainConnector` (address generation / gas / subscriptions),
 * which it does not replace.
 */
interface BlockchainProviderInterface
{
    public function getName(): string;

    /** e.g. 'ethereum', 'polygon', 'solana'. */
    public function getChainId(): int|string;

    public function isAvailable(): bool;

    /** Balance in minor units as a decimal string. @return numeric-string */
    public function getBalance(string $address, ?string $assetCode = null): string;

    /**
     * Canonical transaction status + confirmations.
     *
     * @return array{status: string, confirmations: int, required_confirmations: int, block_height: int|null}
     */
    public function getTransactionStatus(string $txHash): array;

    /** Broadcast an already-signed transaction; returns the tx hash. */
    public function broadcastTransaction(string $signedTransaction, string $idempotencyKey): string;

    /** Estimated network fee in minor units as a decimal string. @return numeric-string */
    public function estimateFee(array $transaction): string;

    public function validateAddress(string $address): bool;

    public function getBlockHeight(): int;
}
