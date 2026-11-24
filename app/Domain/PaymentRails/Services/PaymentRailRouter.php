<?php

declare(strict_types=1);

namespace App\Domain\PaymentRails\Services;

use App\Domain\Banking\Services\IntelligentRoutingService;
use App\Domain\PaymentRails\Enums\PaymentRail;
use App\Domain\PaymentRails\Exceptions\RailUnavailableException;
use App\Domain\PaymentRails\Models\PaymentRailTransaction;
use InvalidArgumentException;

/**
 * Thin router that delegates to IntelligentRoutingService to select the
 * optimal payment rail, then dispatches to the appropriate rail service.
 *
 * Supported beneficiary keys:
 *   name, account_number, routing_number, iban (optional), bic (optional)
 */
final class PaymentRailRouter
{
    public function __construct(
        private readonly IntelligentRoutingService $routing,
        private readonly AchService $ach,
        private readonly FedwireService $fedwire,
        private readonly RtpService $rtp,
        private readonly FedNowService $fednow,
    ) {
    }

    /**
     * Route a payment to the optimal rail and dispatch it.
     *
     * Rails are tried in score order: when the recommended rail is
     * unavailable (disabled, no transmission endpoint), the next
     * alternative is tried. If no rail can carry the payment, this throws
     * {@see RailUnavailableException} and records nothing — a payment
     * that cannot be sent is a refusal, never a stub transaction.
     *
     * @param  array{
     *     name: string,
     *     account_number?: string,
     *     routing_number?: string,
     *     iban?: string,
     *     bic?: string,
     * } $beneficiary
     * @return array{transaction_id: string, rail: string, status: string, transmitted: bool, routing_decision: array<string, mixed>}
     */
    public function route(
        int $userId,
        string $amount,
        string $currency,
        string $country,
        string $urgency,
        array $beneficiary,
    ): array {
        // Normalize to numeric-string for bcmath operations downstream
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be numeric');
        }
        /** @var numeric-string $amount */
        $normalizedAmount = $amount;
        $decision = $this->routing->selectOptimalRail($normalizedAmount, $currency, $country, $urgency);

        $recommendedRail = $decision['recommended_rail'];
        $candidates = array_values(array_unique(array_merge(
            [$recommendedRail],
            array_map(static fn (array $alt): string => $alt['rail'], $decision['alternatives']),
        )));

        $lastUnavailable = null;
        $result = null;
        $usedRail = null;
        foreach ($candidates as $candidate) {
            try {
                $result = $this->dispatch($userId, $candidate, $normalizedAmount, $currency, $beneficiary);
                $usedRail = $candidate;

                break;
            } catch (RailUnavailableException $e) {
                $lastUnavailable = $e;
            }
        }

        if ($result === null || $usedRail === null) {
            throw new RailUnavailableException(
                'No payment rail can carry this payment (tried: ' . implode(', ', $candidates) . '). '
                . ($lastUnavailable !== null ? 'Last refusal: ' . $lastUnavailable->getMessage() : ''),
            );
        }

        $this->routing->logDecision(
            rail: $usedRail,
            score: $decision['score'],
            factors: $decision['decision_factors'],
            transactionId: $result['transaction_id'],
        );

        return [
            'transaction_id'   => $result['transaction_id'],
            'rail'             => $usedRail,
            'status'           => $result['status'],
            'transmitted'      => $result['transmitted'] ?? false,
            'routing_decision' => [
                'recommended_rail' => $decision['recommended_rail'],
                'score'            => $decision['score'],
                'alternatives'     => array_map(
                    static fn (array $alt): string => $alt['rail'],
                    $decision['alternatives'],
                ),
            ],
        ];
    }

    /**
     * Return the available payment rails for a given ISO-3166-1 alpha-2 country code.
     *
     * This is the CAPABILITY list: rails the platform knows for the
     * country. It says nothing about live connectivity — a rail listed
     * here may still be disabled or have no transmission endpoint (see
     * {@see getAvailableRails()} for the rails that can actually carry a
     * payment right now).
     *
     * @return string[]
     */
    public function getSupportedRails(string $country): array
    {
        $upper = strtoupper($country);

        if ($upper === 'US') {
            return [
                PaymentRail::ACH->value,
                PaymentRail::FEDWIRE->value,
                PaymentRail::RTP->value,
                PaymentRail::FEDNOW->value,
            ];
        }

        // Euro-zone countries
        $euCountries = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
            'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];

        if (in_array($upper, $euCountries, true)) {
            return [
                PaymentRail::SEPA->value,
                PaymentRail::SEPA_INSTANT->value,
            ];
        }

        return [PaymentRail::SWIFT->value];
    }

    /**
     * Rails that can actually carry a payment right now: supported for
     * the country AND enabled in config. SEPA / SEPA_INSTANT / SWIFT
     * have no transmission implementation yet, so they are never listed.
     *
     * @return string[]
     */
    public function getAvailableRails(string $country): array
    {
        $enabled = [
            PaymentRail::ACH->value     => (bool) config('payment_rails.ach.enabled', false),
            PaymentRail::FEDWIRE->value => (bool) config('payment_rails.fedwire.enabled', false),
            PaymentRail::RTP->value     => (bool) config('payment_rails.rtp.enabled', false),
            PaymentRail::FEDNOW->value  => (bool) config('payment_rails.fednow.enabled', false),
        ];

        return array_values(array_filter(
            $this->getSupportedRails($country),
            static fn (string $rail): bool => $enabled[$rail] ?? false,
        ));
    }

    /**
     * Look up a PaymentRailTransaction by its UUID and return a status array,
     * or null if not found.
     *
     * @return array{
     *     transaction_id: string,
     *     rail: string,
     *     amount: string,
     *     currency: string,
     *     status: string,
     *     direction: string,
     *     external_id: string|null,
     *     completed_at: string|null,
     *     created_at: string,
     * }|null
     */
    public function getTransactionStatus(string $transactionId, int $userId): ?array
    {
        // Owner only: another user's transaction is "not found".
        /** @var PaymentRailTransaction|null $transaction */
        $transaction = PaymentRailTransaction::query()
            ->whereKey($transactionId)
            ->where('user_id', $userId)
            ->first();

        if ($transaction === null) {
            return null;
        }

        return [
            'transaction_id' => $transaction->id,
            'rail'           => $transaction->rail->value,
            'amount'         => (string) $transaction->amount,
            'currency'       => $transaction->currency,
            'status'         => $transaction->status->value,
            'direction'      => $transaction->direction,
            'external_id'    => $transaction->external_id,
            'completed_at'   => $transaction->completed_at?->toIso8601String(),
            'created_at'     => $transaction->created_at->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch to the concrete rail service that matches $rail.
     *
     * Rails without a dedicated service or transmission endpoint
     * (SEPA, SEPA_INSTANT, SWIFT) fail closed here: the old code recorded
     * a stub "pending" transaction for them, which looked like money in
     * flight. No stub is ever recorded.
     *
     * @param  numeric-string $amount
     * @param  array{
     *     name: string,
     *     account_number?: string,
     *     routing_number?: string,
     *     iban?: string,
     *     bic?: string,
     * } $beneficiary
     * @return array{transaction_id: string, status: string, transmitted: bool}
     */
    private function dispatch(
        int $userId,
        string $rail,
        string $amount,
        string $currency,
        array $beneficiary,
    ): array {
        return match ($rail) {
            PaymentRail::ACH->value     => $this->dispatchAch($userId, $amount, $currency, $beneficiary),
            PaymentRail::FEDWIRE->value => $this->dispatchFedwire($userId, $amount, $currency, $beneficiary),
            PaymentRail::RTP->value     => $this->dispatchRtp($userId, $amount, $currency, $beneficiary),
            PaymentRail::FEDNOW->value  => $this->dispatchFednow($userId, $amount, $currency, $beneficiary),
            default                     => throw new RailUnavailableException(
                "Payment rail '{$rail}' has no transmission implementation yet — no dedicated service and no live endpoint. The payment was not sent.",
            ),
        };
    }

    /**
     * @param  array{name: string, account_number?: string, routing_number?: string, iban?: string, bic?: string} $beneficiary
     * @return array{transaction_id: string, status: string, transmitted: bool}
     */
    private function dispatchAch(int $userId, string $amount, string $currency, array $beneficiary): array
    {
        if (! (bool) config('payment_rails.ach.enabled', false)) {
            throw new RailUnavailableException(
                'ACH is not enabled (payment_rails.ach.enabled=false). No batch was created.',
            );
        }

        $accountNumber = $beneficiary['account_number'] ?? '';
        $routingNumber = $beneficiary['routing_number'] ?? '';

        if ($accountNumber === '' || $routingNumber === '') {
            throw new InvalidArgumentException('ACH requires account_number and routing_number.');
        }

        /** @var numeric-string $achAmount */
        $achAmount = $amount;
        $batch = $this->ach->originateCredit(
            userId: $userId,
            routingNumber: $routingNumber,
            accountNumber: $accountNumber,
            amount: $achAmount,
            name: $beneficiary['name'],
        );

        return [
            'transaction_id' => $batch->batch_id,
            'status'         => $batch->status->value,
            // An ACH batch is a local NACHA file, not a transmitted payment.
            'transmitted' => false,
        ];
    }

    /**
     * @param  array{name: string, account_number?: string, routing_number?: string, iban?: string, bic?: string} $beneficiary
     * @return array{transaction_id: string, status: string, transmitted: bool}
     */
    private function dispatchFedwire(int $userId, string $amount, string $currency, array $beneficiary): array
    {
        $accountNumber = $beneficiary['account_number'] ?? '';
        $routingNumber = $beneficiary['routing_number'] ?? '';

        if ($accountNumber === '' || $routingNumber === '') {
            throw new InvalidArgumentException('Fedwire requires account_number and routing_number.');
        }

        $result = $this->fedwire->sendTransfer(
            userId: $userId,
            amount: $amount,
            currency: $currency,
            beneficiaryName: $beneficiary['name'],
            beneficiaryAccountNumber: $accountNumber,
            beneficiaryRoutingNumber: $routingNumber,
        );

        return [
            'transaction_id' => (string) $result['transaction_id'],
            'status'         => $result['status'],
            'transmitted'    => $result['transmitted'] ?? false,
        ];
    }

    /**
     * @param  array{name: string, account_number?: string, routing_number?: string, iban?: string, bic?: string} $beneficiary
     * @return array{transaction_id: string, status: string, transmitted: bool}
     */
    private function dispatchRtp(int $userId, string $amount, string $currency, array $beneficiary): array
    {
        $accountNumber = $beneficiary['account_number'] ?? '';
        $routingNumber = $beneficiary['routing_number'] ?? '';

        if ($accountNumber === '' || $routingNumber === '') {
            throw new InvalidArgumentException('RTP requires account_number and routing_number.');
        }

        $result = $this->rtp->sendPayment(
            userId: $userId,
            amount: $amount,
            currency: $currency,
            creditorName: $beneficiary['name'],
            creditorAccountNumber: $accountNumber,
            creditorRoutingNumber: $routingNumber,
        );

        return [
            'transaction_id' => (string) $result['transaction_id'],
            'status'         => $result['status'],
            'transmitted'    => $result['transmitted'] ?? false,
        ];
    }

    /**
     * @param  array{name: string, account_number?: string, routing_number?: string, iban?: string, bic?: string} $beneficiary
     * @return array{transaction_id: string, status: string, transmitted: bool}
     */
    private function dispatchFednow(int $userId, string $amount, string $currency, array $beneficiary): array
    {
        $iban = $beneficiary['iban'] ?? '';
        $bic = $beneficiary['bic'] ?? '';

        // FedNow needs a real creditor IBAN/BIC. The old code synthesised
        // one from routing + account number ('US' . routing . account) —
        // a fabricated identifier that could never clear. Refuse instead.
        if ($iban === '' || $bic === '') {
            throw new InvalidArgumentException('FedNow requires iban and bic.');
        }

        $result = $this->fednow->sendInstantPayment(
            userId: $userId,
            amount: $amount,
            currency: $currency,
            creditorName: $beneficiary['name'],
            creditorIban: $iban,
            creditorBic: $bic,
        );

        return [
            'transaction_id' => (string) $result['transaction_id'],
            'status'         => $result['status'],
            'transmitted'    => $result['transmitted'] ?? false,
        ];
    }
}
