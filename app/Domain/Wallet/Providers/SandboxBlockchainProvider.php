<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers;

use App\Domain\Wallet\Contracts\BlockchainProviderInterface;
use RuntimeException;

/**
 * Sandbox blockchain provider for local/testing/demo only. Tracks
 * broadcast transactions in memory and reports confirmations from an
 * operator-set block height — it never touches a real chain and never
 * holds real funds. Refuses to construct in production.
 */
final class SandboxBlockchainProvider implements BlockchainProviderInterface
{
    /** @var array<string, array{confirmations: int, block_height: int}> */
    private array $transactions = [];

    private int $blockHeight = 1000;

    public function __construct(private readonly string $chainId = 'sandbox')
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SandboxBlockchainProvider cannot be used in production.');
        }
    }

    public function getName(): string
    {
        return 'sandbox';
    }

    public function getChainId(): int|string
    {
        return $this->chainId;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getBalance(string $address, ?string $assetCode = null): string
    {
        return '0';
    }

    public function getTransactionStatus(string $txHash): array
    {
        $tx = $this->transactions[$txHash] ?? null;
        if ($tx === null) {
            return ['status' => 'unknown', 'confirmations' => 0, 'required_confirmations' => 0, 'block_height' => $this->blockHeight];
        }

        return [
            'status'                 => $tx['confirmations'] > 0 ? 'confirmed' : 'pending',
            'confirmations'          => $tx['confirmations'],
            'required_confirmations' => 0,
            'block_height'           => $this->blockHeight,
        ];
    }

    public function broadcastTransaction(string $signedTransaction, string $idempotencyKey): string
    {
        $txHash = '0x' . substr(hash('sha256', $signedTransaction . $idempotencyKey), 0, 64);
        $this->transactions[$txHash] ??= ['confirmations' => 0, 'block_height' => $this->blockHeight];

        return $txHash;
    }

    public function estimateFee(array $transaction): string
    {
        return '0';
    }

    public function validateAddress(string $address): bool
    {
        return $address !== '' && preg_match('/^(0x[0-9a-fA-F]{40}|[1-9A-HJ-NP-Za-km-z]{32,44})$/', $address) === 1;
    }

    public function getBlockHeight(): int
    {
        return $this->blockHeight;
    }

    /** Test/operator helper: advance the chain and confirm a tx. */
    public function confirmTransaction(string $txHash, int $confirmations = 12): void
    {
        $this->transactions[$txHash] = ['confirmations' => $confirmations, 'block_height' => $this->blockHeight];
    }

    public function advanceBlocks(int $blocks = 1): void
    {
        $this->blockHeight += $blocks;
    }
}
