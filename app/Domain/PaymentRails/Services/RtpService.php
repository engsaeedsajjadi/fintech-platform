<?php

declare(strict_types=1);

namespace App\Domain\PaymentRails\Services;

use App\Domain\PaymentRails\Enums\PaymentRail;
use App\Domain\PaymentRails\Enums\RailStatus;
use App\Domain\PaymentRails\Models\PaymentRailTransaction;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RtpService
{
    /**
     * Send a Real-Time Payment via The Clearing House RTP network.
     *
     * There is no live RTP transmission endpoint: the instruction is
     * recorded ONLY in simulation mode (see {@see RailTransmissionGate})
     * with status INITIATED and `transmitted: false`. Outside simulation
     * mode this throws {@see RailUnavailableException} and creates no row.
     *
     * @throws InvalidArgumentException when amount exceeds the configured maximum
     * @throws \App\Domain\PaymentRails\Exceptions\RailUnavailableException when the rail cannot transmit
     * @return array{transaction_id: string, external_id: string, status: string, rail: string, amount: string, currency: string, transmitted: bool, simulation: bool}
     */
    public function sendPayment(
        int $userId,
        string $amount,
        string $currency,
        string $creditorName,
        string $creditorAccountNumber,
        string $creditorRoutingNumber,
        ?string $reference = null,
    ): array {
        RailTransmissionGate::ensureSimulationAllowed('RTP', 'rtp');

        $maxAmount = (int) config('payment_rails.rtp.max_amount', 100000000);

        // Exact decimal comparison: (float) $amount * 100 loses cents at
        // the boundary (e.g. 1000000.01 becomes 100000000.999...).
        if (! is_numeric($amount) || bccomp(bcmul($amount, '100', 2), (string) $maxAmount, 2) === 1) {
            throw new InvalidArgumentException(
                "RTP amount {$amount} exceeds maximum allowed {$maxAmount} cents."
            );
        }

        $externalId = 'RTP-' . strtoupper(Str::random(16));

        $transaction = PaymentRailTransaction::create([
            'user_id'     => $userId,
            'rail'        => PaymentRail::RTP,
            'external_id' => $externalId,
            'amount'      => $amount,
            'currency'    => $currency,
            // INITIATED, not PROCESSING: nothing was transmitted.
            'status'    => RailStatus::INITIATED,
            'direction' => 'debit',
            'metadata'  => [
                'creditor_name'           => $creditorName,
                'creditor_account_number' => $creditorAccountNumber,
                'creditor_routing_number' => $creditorRoutingNumber,
                'reference'               => $reference,
                'participant_id'          => config('payment_rails.rtp.participant_id'),
                'type'                    => 'send',
                'transmitted'             => false,
                'simulation'              => true,
            ],
        ]);

        return [
            'transaction_id' => $transaction->id,
            'external_id'    => $externalId,
            'status'         => RailStatus::INITIATED->value,
            'rail'           => PaymentRail::RTP->value,
            'amount'         => $amount,
            'currency'       => $currency,
            'transmitted'    => false,
            'simulation'     => true,
        ];
    }

    /**
     * Issue a Request for Payment (RfP) via the RTP network.
     *
     * Same gate as sendPayment: no live endpoint exists, so the request
     * is recorded ONLY in simulation mode, as INITIATED and untransmitted.
     *
     * @throws \App\Domain\PaymentRails\Exceptions\RailUnavailableException when the rail cannot transmit
     * @return array{transaction_id: string, external_id: string, status: string, rail: string, amount: string, currency: string, transmitted: bool, simulation: bool}
     */
    public function requestPayment(
        int $userId,
        string $amount,
        string $currency,
        string $debtorName,
        string $debtorRoutingNumber,
        ?string $reference = null,
    ): array {
        RailTransmissionGate::ensureSimulationAllowed('RTP', 'rtp');

        $externalId = 'RTP-RFP-' . strtoupper(Str::random(12));

        $transaction = PaymentRailTransaction::create([
            'user_id'     => $userId,
            'rail'        => PaymentRail::RTP,
            'external_id' => $externalId,
            'amount'      => $amount,
            'currency'    => $currency,
            'status'      => RailStatus::INITIATED,
            'direction'   => 'credit',
            'metadata'    => [
                'debtor_name'           => $debtorName,
                'debtor_routing_number' => $debtorRoutingNumber,
                'reference'             => $reference,
                'participant_id'        => config('payment_rails.rtp.participant_id'),
                'type'                  => 'request',
                'transmitted'           => false,
                'simulation'            => true,
            ],
        ]);

        return [
            'transaction_id' => $transaction->id,
            'external_id'    => $externalId,
            'status'         => RailStatus::INITIATED->value,
            'rail'           => PaymentRail::RTP->value,
            'amount'         => $amount,
            'currency'       => $currency,
            'transmitted'    => false,
            'simulation'     => true,
        ];
    }

    /**
     * Retrieve the status of an RTP transaction by internal transaction ID.
     *
     * @return array{transaction_id: string, external_id: string|null, status: string, rail: string, amount: string, currency: string}|null
     */
    public function getPaymentStatus(string $transactionId): ?array
    {
        $transaction = PaymentRailTransaction::where('id', $transactionId)
            ->where('rail', PaymentRail::RTP->value)
            ->first();

        if ($transaction === null) {
            return null;
        }

        return [
            'transaction_id' => $transaction->id,
            'external_id'    => $transaction->external_id,
            'status'         => $transaction->status->value,
            'rail'           => $transaction->rail->value,
            'amount'         => $transaction->amount,
            'currency'       => $transaction->currency,
        ];
    }
}
