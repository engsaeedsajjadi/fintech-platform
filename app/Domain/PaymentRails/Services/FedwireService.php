<?php

declare(strict_types=1);

namespace App\Domain\PaymentRails\Services;

use App\Domain\PaymentRails\Enums\PaymentRail;
use App\Domain\PaymentRails\Enums\RailStatus;
use App\Domain\PaymentRails\Models\PaymentRailTransaction;
use Illuminate\Support\Str;

final class FedwireService
{
    /**
     * Initiate a Fedwire RTGS transfer.
     *
     * There is no live Fedwire transmission endpoint: the instruction is
     * recorded ONLY in simulation mode (see {@see RailTransmissionGate})
     * with status INITIATED and `transmitted: false`. Outside simulation
     * mode this throws {@see RailUnavailableException} and creates no row.
     *
     * @throws \App\Domain\PaymentRails\Exceptions\RailUnavailableException when the rail cannot transmit
     * @return array{transaction_id: string, external_id: string, status: string, rail: string, amount: string, currency: string, transmitted: bool, simulation: bool}
     */
    public function sendTransfer(
        int $userId,
        string $amount,
        string $currency,
        string $beneficiaryName,
        string $beneficiaryAccountNumber,
        string $beneficiaryRoutingNumber,
        ?string $reference = null,
    ): array {
        RailTransmissionGate::ensureSimulationAllowed('Fedwire', 'fedwire');

        $externalId = 'FW-' . strtoupper(Str::random(16));

        $transaction = PaymentRailTransaction::create([
            'user_id'     => $userId,
            'rail'        => PaymentRail::FEDWIRE,
            'external_id' => $externalId,
            'amount'      => $amount,
            'currency'    => $currency,
            // INITIATED, not PROCESSING: nothing was transmitted.
            'status'    => RailStatus::INITIATED,
            'direction' => 'debit',
            'metadata'  => [
                'beneficiary_name'           => $beneficiaryName,
                'beneficiary_account_number' => $beneficiaryAccountNumber,
                'beneficiary_routing_number' => $beneficiaryRoutingNumber,
                'reference'                  => $reference,
                'sender_aba'                 => config('payment_rails.fedwire.sender_aba'),
                'transmitted'                => false,
                'simulation'                 => true,
            ],
        ]);

        return [
            'transaction_id' => $transaction->id,
            'external_id'    => $externalId,
            'status'         => RailStatus::INITIATED->value,
            'rail'           => PaymentRail::FEDWIRE->value,
            'amount'         => $amount,
            'currency'       => $currency,
            'transmitted'    => false,
            'simulation'     => true,
        ];
    }

    /**
     * Retrieve the status of a Fedwire transfer by internal transaction ID.
     *
     * @return array{transaction_id: string, external_id: string|null, status: string, rail: string, amount: string, currency: string}|null
     */
    public function getTransferStatus(string $transactionId): ?array
    {
        $transaction = PaymentRailTransaction::where('id', $transactionId)
            ->where('rail', PaymentRail::FEDWIRE->value)
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

    /**
     * Process an inbound Fedwire status callback.
     *
     * @return array{transaction_id: string, external_id: string, status: string, updated: bool}
     */
    public function processCallback(
        string $externalId,
        string $status,
        ?string $errorMessage = null,
    ): array {
        $transaction = PaymentRailTransaction::where('external_id', $externalId)
            ->where('rail', PaymentRail::FEDWIRE->value)
            ->first();

        if ($transaction === null) {
            return [
                'transaction_id' => '',
                'external_id'    => $externalId,
                'status'         => $status,
                'updated'        => false,
            ];
        }

        $railStatus = RailStatus::tryFrom(strtolower($status)) ?? RailStatus::FAILED;

        $updates = ['status' => $railStatus];

        if ($errorMessage !== null) {
            $updates['error_message'] = $errorMessage;
        }

        if ($railStatus->isTerminal()) {
            $updates['completed_at'] = now();
        }

        $transaction->update($updates);

        return [
            'transaction_id' => $transaction->id,
            'external_id'    => $externalId,
            'status'         => $railStatus->value,
            'updated'        => true,
        ];
    }
}
