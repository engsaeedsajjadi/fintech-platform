<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\MobilePayment\Enums\PaymentAsset;
use App\Domain\MobilePayment\Enums\PaymentNetwork;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WalletTransferService
{
    /**
     * Validate a blockchain address for a specific network.
     *
     * @return array{valid: bool, network: string, address: string, address_type: string|null, error: string|null}
     */
    public function validateAddress(string $address, string $network): array
    {
        $paymentNetwork = PaymentNetwork::tryFrom($network);

        if (! $paymentNetwork) {
            return [
                'valid'        => false,
                'network'      => $network,
                'address'      => $address,
                'address_type' => null,
                'error'        => 'Unsupported network. Supported: ' . implode(', ', PaymentNetwork::values()),
            ];
        }

        $pattern = $paymentNetwork->addressPattern();
        $isValid = (bool) preg_match($pattern, $address);

        return [
            'valid'        => $isValid,
            'network'      => $paymentNetwork->value,
            'address'      => $address,
            'address_type' => $isValid ? $this->detectAddressType($address, $paymentNetwork) : null,
            'error'        => $isValid ? null : "Invalid {$paymentNetwork->label()} address format.",
        ];
    }

    /**
     * Resolve an ENS/SNS name to a blockchain address.
     *
     * @return array{resolved: bool, name: string, address: string|null, network: string, error: string|null}
     */
    public function resolveName(string $name, string $network): array
    {
        $paymentNetwork = PaymentNetwork::tryFrom($network);

        if (! $paymentNetwork) {
            return [
                'resolved' => false,
                'name'     => $name,
                'address'  => null,
                'network'  => $network,
                'error'    => 'Unsupported network. Supported: ' . implode(', ', PaymentNetwork::values()),
            ];
        }

        return $this->performNameResolution($name, $paymentNetwork);
    }

    /**
     * Get a fee quote for a wallet-to-wallet transfer.
     *
     * @return array{network: string, asset: string, estimated_fee: string, fee_currency: string, estimated_time_seconds: int, exchange_rate_usd: string}
     */
    public function getTransferQuote(string $network, string $asset, string $amount): array
    {
        $paymentNetwork = PaymentNetwork::from($network);
        $paymentAsset = PaymentAsset::from($asset);

        $gasCostUsd = $paymentNetwork->averageGasCostUsd();
        // Decimal strings only: the gas estimate is a float from the
        // network enum, converted once to a 6dp string; the total is
        // bcmath, never (float) $amount + $gasCostUsd.
        $gasCost = sprintf('%.6F', $gasCostUsd);

        return [
            'network'                => $paymentNetwork->value,
            'asset'                  => $paymentAsset->value,
            'amount'                 => $amount,
            'estimated_fee'          => $gasCost,
            'fee_currency'           => 'USD',
            'estimated_time_seconds' => $this->estimateTransferTime($paymentNetwork),
            'exchange_rate_usd'      => '1.000000',
        ];
    }

    /**
     * Get a transaction quote with recipient address validation.
     *
     * @return array{quote_id: string, to: string, amount: string, asset: string, network: string, fee: string, total: string, expires_at: string}
     */
    public function getTransactionQuote(string $to, string $network, string $asset, string $amount): array
    {
        $paymentNetwork = PaymentNetwork::from($network);
        $paymentAsset = PaymentAsset::from($asset);

        // Validate recipient address
        $validation = $this->validateAddress($to, $network);
        if (! $validation['valid']) {
            throw new InvalidArgumentException('Invalid recipient address: ' . ($validation['error'] ?? 'unknown error'));
        }

        $gasCostUsd = $paymentNetwork->averageGasCostUsd();
        // Decimal strings only: (float) $amount + $gasCostUsd lost exactness
        // (e.g. float rounding on large amounts). Both operands are decimal
        // strings here and the total is bcmath at 6dp.
        $gasCost = sprintf('%.6F', $gasCostUsd);
        $total = DecimalMath::add($amount, $gasCost, 6);

        return [
            'quote_id'   => 'quote_' . Str::random(20),
            'to'         => $to,
            'amount'     => $amount,
            'asset'      => $paymentAsset->value,
            'network'    => $paymentNetwork->value,
            'fee'        => $gasCost,
            'total'      => $total,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ];
    }

    /**
     * Detect address type based on format heuristics.
     */
    private function detectAddressType(string $address, PaymentNetwork $network): string
    {
        if ($network->isEvm()) {
            return 'eoa';
        }

        return match ($network) {
            PaymentNetwork::SOLANA => strlen($address) >= 40 ? 'program' : 'wallet',
            PaymentNetwork::TRON   => str_starts_with($address, 'T') ? 'base58' : 'hex',
            default                => 'unknown',
        };
    }

    /**
     * Perform name resolution for the given name and network.
     *
     * In production, this would call external resolvers (SNS for Solana, etc.).
     * Currently returns a stub response indicating resolution is not yet available.
     *
     * @return array{resolved: bool, name: string, address: string|null, network: string, error: string|null}
     */
    private function performNameResolution(string $name, PaymentNetwork $network): array
    {
        // SNS (.sol) resolution for Solana
        if ($network === PaymentNetwork::SOLANA && str_ends_with($name, '.sol')) {
            return [
                'resolved' => false,
                'name'     => $name,
                'address'  => null,
                'network'  => $network->value,
                'error'    => 'SNS resolution requires external resolver integration. Please use a wallet address directly.',
            ];
        }

        // ENS (.eth) - supported on EVM chains but requires external resolver
        if (str_ends_with($name, '.eth')) {
            if ($network->isEvm()) {
                return [
                    'resolved' => false,
                    'name'     => $name,
                    'address'  => null,
                    'network'  => $network->value,
                    'error'    => 'ENS resolution requires external resolver integration. Please use a wallet address directly.',
                ];
            }

            return [
                'resolved' => false,
                'name'     => $name,
                'address'  => null,
                'network'  => $network->value,
                'error'    => 'ENS names (.eth) are not supported on ' . $network->label() . '. Please use a wallet address directly.',
            ];
        }

        return [
            'resolved' => false,
            'name'     => $name,
            'address'  => null,
            'network'  => $network->value,
            'error'    => 'Name resolution is not available for this format. Please use a wallet address directly.',
        ];
    }

    /**
     * Estimate transfer confirmation time in seconds.
     */
    private function estimateTransferTime(PaymentNetwork $network): int
    {
        return $network->avgConfirmationSeconds();
    }
}
