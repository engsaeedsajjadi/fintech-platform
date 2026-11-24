<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Payment\Contracts\PaymentServiceInterface;
use App\Domain\Payment\DataObjects\BankWithdrawal;
use App\Domain\Payment\DataObjects\OpenBankingDeposit;
use App\Domain\Payment\DataObjects\StripeDeposit;
use App\Domain\Payment\Enums\PaymentIntentDirection;
use App\Domain\Payment\Enums\PaymentIntentKind;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payment\Workflows\ProcessBankWithdrawalWorkflow;
use App\Domain\Payment\Workflows\ProcessOpenBankingDepositWorkflow;
use App\Domain\Payment\Workflows\ProcessStripeDepositWorkflow;
use Throwable;
use Workflow\WorkflowStub;

/**
 * Production payment service. Every call first creates a persisted
 * {@see PaymentIntent} (INITIATED) and returns the intent's id — never
 * a fabricated `'txn_'.uniqid()`. The intent moves to PROCESSING when
 * the workflow starts; its final state comes only from the provider
 * (webhook / status query / reconciliation), never from this method.
 */
class ProductionPaymentService implements PaymentServiceInterface
{
    public function __construct(
        private readonly ?PaymentIntentService $intents = null,
    ) {
    }

    private function intentService(): PaymentIntentService
    {
        return $this->intents ?? app(PaymentIntentService::class);
    }

    /**
     * Process a Stripe deposit.
     *
     * @return string The persisted payment-intent id (UUID), not a provider id.
     */
    public function processStripeDeposit(array $data): string
    {
        $intent = $this->intentService()->create([
            'account_uuid'       => $data['account_uuid'],
            'direction'          => PaymentIntentDirection::INCOMING,
            'kind'               => PaymentIntentKind::DEPOSIT,
            'amount_minor'       => PaymentIntentService::amountToMinor($data['amount'], (string) $data['currency']),
            'currency'           => (string) $data['currency'],
            'provider'           => 'stripe',
            'provider_reference' => $data['external_reference'] ?? null,
            'metadata'           => array_merge($data['metadata'] ?? [], ['reference' => $data['reference'] ?? null]),
        ], $this->idempotencyKey('stripe-deposit:', $data));

        $deposit = new StripeDeposit(
            accountUuid: $data['account_uuid'],
            amount: $intent->amount_minor,
            currency: $data['currency'],
            reference: $data['reference'],
            externalReference: $data['external_reference'],
            paymentMethod: $data['payment_method'],
            paymentMethodType: $data['payment_method_type'],
            metadata: $data['metadata'] ?? []
        );

        $this->startWorkflow($intent, ProcessStripeDepositWorkflow::class, $deposit);

        return $intent->id;
    }

    /**
     * Process a bank withdrawal.
     *
     * @return array{reference: string, status: string, intent_id: string}
     */
    public function processBankWithdrawal(array $data): array
    {
        $intent = $this->intentService()->create([
            'account_uuid'       => $data['account_uuid'],
            'direction'          => PaymentIntentDirection::OUTGOING,
            'kind'               => PaymentIntentKind::WITHDRAWAL,
            'amount_minor'       => PaymentIntentService::amountToMinor($data['amount'], (string) $data['currency']),
            'currency'           => (string) $data['currency'],
            'provider'           => 'bank',
            'provider_reference' => $data['external_reference'] ?? null,
            'metadata'           => array_merge($data['metadata'] ?? [], ['reference' => $data['reference'] ?? null]),
        ], $this->idempotencyKey('bank-withdrawal:', $data));

        $withdrawal = new BankWithdrawal(
            accountUuid: $data['account_uuid'],
            amount: $intent->amount_minor,
            currency: $data['currency'],
            reference: $data['reference'],
            bankName: $data['bank_name'],
            accountNumber: $data['account_number'],
            accountHolderName: $data['account_holder_name'],
            routingNumber: $data['routing_number'] ?? null,
            iban: $data['iban'] ?? null,
            swift: $data['swift'] ?? null,
            metadata: $data['metadata'] ?? []
        );

        $this->startWorkflow($intent, ProcessBankWithdrawalWorkflow::class, $withdrawal);

        // Return withdrawal reference and status
        // In production, status updates would come via webhooks
        return [
            'reference' => $data['reference'],
            'status'    => 'processing',
            'intent_id' => $intent->id,
        ];
    }

    /**
     * Process an OpenBanking deposit.
     *
     * @return string The persisted payment-intent id (UUID), not a provider id.
     */
    public function processOpenBankingDeposit(array $data): string
    {
        $intent = $this->intentService()->create([
            'account_uuid'       => $data['account_uuid'],
            'direction'          => PaymentIntentDirection::INCOMING,
            'kind'               => PaymentIntentKind::DEPOSIT,
            'amount_minor'       => PaymentIntentService::amountToMinor($data['amount'], (string) $data['currency']),
            'currency'           => (string) $data['currency'],
            'provider'           => 'openbanking',
            'provider_reference' => $data['external_reference'] ?? null,
            'metadata'           => array_merge($data['metadata'] ?? [], ['reference' => $data['reference'] ?? null]),
        ], $this->idempotencyKey('openbanking-deposit:', $data));

        $deposit = new OpenBankingDeposit(
            accountUuid: $data['account_uuid'],
            amount: $intent->amount_minor,
            currency: $data['currency'],
            reference: $data['reference'],
            bankName: $data['bank_name'],
            metadata: $data['metadata'] ?? []
        );

        $this->startWorkflow($intent, ProcessOpenBankingDepositWorkflow::class, $deposit);

        return $intent->id;
    }

    /**
     * Start the workflow and move the intent to PROCESSING. If the start
     * itself throws, the intent is FAILED with the reason — an intent is
     * never left INITIATED after a failed submission, and never reported
     * successful without provider evidence. An intent that is already
     * past INITIATED (a retried call with the same idempotency key) is
     * NOT submitted again.
     *
     * @param  class-string  $workflowClass
     */
    private function startWorkflow(PaymentIntent $intent, string $workflowClass, object $payload): void
    {
        if ($intent->state !== TransactionState::INITIATED) {
            return;
        }

        try {
            $workflow = WorkflowStub::make($workflowClass);
            $workflow->start($payload);
            $this->intentService()->transition($intent, TransactionState::PROCESSING, 'workflow started');
        } catch (Throwable $e) {
            $this->intentService()->transition($intent, TransactionState::FAILED, 'workflow failed to start: ' . $e->getMessage());

            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    private function idempotencyKey(string $prefix, array $data): ?string
    {
        $suffix = (string) ($data['reference'] ?? $data['external_reference'] ?? '');

        return $suffix !== '' ? $prefix . $suffix : null;
    }
}
