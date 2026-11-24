<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use InvalidArgumentException;

/**
 * Payment Service Interface
 * Handles payment processing and integration with payment gateways.
 *
 * NOTE: this is still the legacy mock implementation (it returns canned
 * results and calls no gateway). Amounts are integer minor units or exact
 * decimal strings throughout — floats are never accepted for money.
 * Production payment processing goes through PaymentServiceInterface
 * implementations (ProductionPaymentService / SandboxPaymentService).
 */
class PaymentService
{
    /**
     * Process a payment.
     *
     * @param array{amount_minor?: int, amount?: int, currency?: string} $paymentData
     */
    public function processPayment(array $paymentData): array
    {
        $amountMinor = $paymentData['amount_minor'] ?? $paymentData['amount'] ?? null;

        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('Payment amount must be a positive integer in minor units.');
        }

        // Mock payment processing
        // In production, this would integrate with actual payment gateways
        return [
            'success'        => true,
            'transaction_id' => 'payment_' . uniqid(),
            'status'         => 'completed',
            'amount_minor'   => $amountMinor,
            'currency'       => $paymentData['currency'] ?? 'USD',
            'processed_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * Verify a payment.
     */
    public function verifyPayment(string $transactionId): array
    {
        return [
            'verified'     => true,
            'status'       => 'completed',
            'amount_minor' => 0,
            'currency'     => 'USD',
        ];
    }

    /**
     * Refund a payment.
     *
     * @param int $amountMinorUnits Refund amount in integer minor units (e.g. cents for USD).
     */
    public function refundPayment(string $transactionId, int $amountMinorUnits, string $currency, string $reason): array
    {
        if ($amountMinorUnits <= 0) {
            throw new InvalidArgumentException('Refund amount must be a positive integer in minor units.');
        }

        return [
            'success'      => true,
            'refund_id'    => 'refund_' . uniqid(),
            'amount_minor' => $amountMinorUnits,
            'currency'     => $currency,
            'reason'       => $reason,
            'status'       => 'processed',
        ];
    }
}
