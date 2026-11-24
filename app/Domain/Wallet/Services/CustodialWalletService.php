<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Custodian\Contracts\ICustodianConnector;
use App\Domain\Custodian\Services\CustodianRegistry;
use App\Domain\Custodian\ValueObjects\TransactionReceipt;
use App\Domain\Custodian\ValueObjects\TransferRequest;
use App\Domain\Wallet\Exceptions\CustodyUnavailableException;

/**
 * Custodial settlement behind CustodyProviderInterface — which is an
 * alias of the existing ICustodianConnector seam (one seam, no duplicate).
 * Fails closed: with no licensed custodian configured
 * (wallet.custody.provider empty) or with a demo/mock connector outside
 * local/testing/demo, every operation throws CustodyUnavailableException
 * instead of silently settling against a fake custodian.
 *
 * Runtime return type is ICustodianConnector: CustodyProviderInterface
 * extends it as the target type-hint, but no connector declares the
 * alias yet, so resolving to the alias type would fail at runtime.
 * Connectors adopt the alias name when they are next touched.
 */
final class CustodialWalletService
{
    public function __construct(
        private readonly CustodianRegistry $registry,
    ) {
    }

    public function resolveCustodian(): ICustodianConnector
    {
        $name = trim((string) config('wallet.custody.provider', ''));
        if ($name === '') {
            throw new CustodyUnavailableException('No custodian is configured (WALLET_CUSTODY_PROVIDER). Custodial settlement is unavailable until a licensed custodian is contracted and configured.');
        }

        $connector = $this->registry->get($name);
        if (! $connector instanceof ICustodianConnector) {
            throw new CustodyUnavailableException("Custodian '{$name}' does not implement the custody seam.");
        }

        if (! app()->environment(['local', 'testing', 'demo']) && in_array(strtolower($name), ['demo', 'mock'], true)) {
            throw new CustodyUnavailableException("Custodian '{$name}' is a demo/mock connector and cannot settle in this environment.");
        }

        return $connector;
    }

    /** @return array<string, mixed> */
    public function getCustodianBalance(string $accountId, string $assetCode): array
    {
        $custodian = $this->resolveCustodian();
        $balance = $custodian->getBalance($accountId, $assetCode);

        return [
            'custodian'     => $custodian->getName(),
            'account_id'    => $accountId,
            'asset_code'    => $assetCode,
            'balance_minor' => $balance->getAmount(),
        ];
    }

    public function initiateCustodyTransfer(TransferRequest $request): TransactionReceipt
    {
        return $this->resolveCustodian()->initiateTransfer($request);
    }
}
