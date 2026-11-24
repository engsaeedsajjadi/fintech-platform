<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Models\WalletDepositWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deposit webhook application. The raw payload is stored FIRST
 * (WalletDepositWebhook, unique on provider + provider_event_id) and a
 * duplicate delivery is acknowledged and no-oped — the ledger is never
 * posted twice for one provider event. Signature verification itself
 * belongs to the owning webhook controller (Custodian /
 * WebhookVerificationService pattern) before this service is called.
 */
final class DepositWebhookService
{
    public function __construct(
        private readonly FiatWalletService $fiatWallet,
    ) {
    }

    /**
     * @return array{status: string, fiat_deposit_uuid: string|null, provider_event_id: string}
     */
    public function applyDepositWebhook(
        string $provider,
        string $providerEventId,
        string $accountUuid,
        string $currency,
        string $amount,
        string $providerReference,
        array $payload = [],
    ): array {
        $webhook = $this->storeRaw($provider, $providerEventId, $accountUuid, $currency, $amount, $providerReference, $payload);

        if ($webhook->status === WalletDepositWebhook::STATUS_APPLIED || $webhook->status === WalletDepositWebhook::STATUS_DUPLICATE) {
            return [
                'status'            => 'duplicate',
                'fiat_deposit_uuid' => $webhook->fiat_deposit_uuid,
                'provider_event_id' => $providerEventId,
            ];
        }

        try {
            $deposit = $this->fiatWallet->deposit(
                $accountUuid,
                $currency,
                $amount,
                "webhook:{$provider}:{$providerEventId}",
                $providerReference,
            );
        } catch (Throwable $e) {
            $webhook->update(['status' => WalletDepositWebhook::STATUS_FAILED]);
            throw $e;
        }

        $webhook->update([
            'status'            => WalletDepositWebhook::STATUS_APPLIED,
            'fiat_deposit_uuid' => $deposit->uuid,
            'applied_at'        => now(),
        ]);

        return [
            'status'            => 'applied',
            'fiat_deposit_uuid' => $deposit->uuid,
            'provider_event_id' => $providerEventId,
        ];
    }

    private function storeRaw(
        string $provider,
        string $providerEventId,
        string $accountUuid,
        string $currency,
        string $amount,
        string $providerReference,
        array $payload,
    ): WalletDepositWebhook {
        try {
            return DB::transaction(function () use ($provider, $providerEventId, $accountUuid, $currency, $amount, $providerReference, $payload): WalletDepositWebhook {
                $existing = WalletDepositWebhook::query()
                    ->where('provider', $provider)->where('provider_event_id', $providerEventId)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                return WalletDepositWebhook::query()->create([
                    'provider'           => $provider,
                    'provider_event_id'  => $providerEventId,
                    'account_uuid'       => $accountUuid,
                    'currency'           => $currency,
                    'amount'             => DecimalMath::scale4($amount),
                    'provider_reference' => $providerReference,
                    'status'             => 'received',
                    'payload'            => $payload,
                ]);
            });
        } catch (QueryException) {
            // Lost a race with a concurrent delivery of the same event.
            return WalletDepositWebhook::query()
                ->where('provider', $provider)->where('provider_event_id', $providerEventId)
                ->firstOrFail();
        }
    }
}
