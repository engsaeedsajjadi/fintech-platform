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
use Illuminate\Support\Facades\Log;
use Throwable;
use Workflow\WorkflowStub;

/**
 * Sandbox Payment Service that uses real external APIs in test/sandbox mode.
 * Connects to payment processors' sandbox environments for realistic testing.
 *
 * Like the production service, every call first creates a persisted
 * {@see PaymentIntent} and returns the intent's id — never a fabricated
 * `'sandbox_txn_'.uniqid()`. The intent moves to PROCESSING when the
 * workflow starts; its final state comes only from the provider.
 */
class SandboxPaymentService implements PaymentServiceInterface
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
     * Process a Stripe deposit using Stripe's test mode.
     * Uses real Stripe API with test keys for sandbox environment.
     *
     * @return string The persisted payment-intent id (UUID).
     */
    public function processStripeDeposit(array $data): string
    {
        Log::info('Processing sandbox Stripe deposit', array_merge($data, [
            'environment' => 'sandbox',
        ]));

        // Add sandbox-specific metadata
        $sandboxData = $data;
        $sandboxData['metadata'] = array_merge($data['metadata'] ?? [], [
            'environment' => 'sandbox',
            'test_mode'   => true,
        ]);

        $intent = $this->intentService()->create([
            'account_uuid'       => $sandboxData['account_uuid'],
            'direction'          => PaymentIntentDirection::INCOMING,
            'kind'               => PaymentIntentKind::DEPOSIT,
            'amount_minor'       => PaymentIntentService::amountToMinor($sandboxData['amount'], (string) $sandboxData['currency']),
            'currency'           => (string) $sandboxData['currency'],
            'provider'           => 'stripe',
            'provider_reference' => $sandboxData['external_reference'] ?? null,
            'metadata'           => array_merge($sandboxData['metadata'], ['reference' => $sandboxData['reference'] ?? null]),
        ], $this->idempotencyKey('sandbox-stripe-deposit:', $sandboxData));

        // Use the production workflow with sandbox Stripe keys
        $deposit = new StripeDeposit(
            accountUuid: $sandboxData['account_uuid'],
            amount: $intent->amount_minor,
            currency: $sandboxData['currency'],
            reference: $sandboxData['reference'],
            externalReference: $sandboxData['external_reference'],
            paymentMethod: $sandboxData['payment_method'],
            paymentMethodType: $sandboxData['payment_method_type'],
            metadata: $sandboxData['metadata']
        );

        $this->startWorkflow($intent, ProcessStripeDepositWorkflow::class, $deposit);

        return $intent->id;
    }

    /**
     * Process a bank withdrawal using banking sandbox APIs.
     * Connects to bank sandbox environments for testing.
     *
     * @return array{reference: string, status: string, intent_id: string}
     */
    public function processBankWithdrawal(array $data): array
    {
        Log::info('Processing sandbox bank withdrawal', array_merge($data, [
            'environment' => 'sandbox',
        ]));

        // Add sandbox-specific metadata
        $sandboxData = $data;
        $sandboxData['metadata'] = array_merge($data['metadata'] ?? [], [
            'environment' => 'sandbox',
            'test_mode'   => true,
        ]);

        $intent = $this->intentService()->create([
            'account_uuid'       => $sandboxData['account_uuid'],
            'direction'          => PaymentIntentDirection::OUTGOING,
            'kind'               => PaymentIntentKind::WITHDRAWAL,
            'amount_minor'       => PaymentIntentService::amountToMinor($sandboxData['amount'], (string) $sandboxData['currency']),
            'currency'           => (string) $sandboxData['currency'],
            'provider'           => 'bank',
            'provider_reference' => $sandboxData['external_reference'] ?? null,
            'metadata'           => array_merge($sandboxData['metadata'], ['reference' => $sandboxData['reference'] ?? null]),
        ], $this->idempotencyKey('sandbox-bank-withdrawal:', $sandboxData));

        // Use the production workflow with sandbox bank credentials
        $withdrawal = new BankWithdrawal(
            accountUuid: $sandboxData['account_uuid'],
            amount: $intent->amount_minor,
            currency: $sandboxData['currency'],
            reference: $sandboxData['reference'],
            bankName: $sandboxData['bank_name'],
            accountNumber: $sandboxData['account_number'],
            accountHolderName: $sandboxData['account_holder_name'],
            routingNumber: $sandboxData['routing_number'] ?? null,
            iban: $sandboxData['iban'] ?? null,
            swift: $sandboxData['swift'] ?? null,
            metadata: $sandboxData['metadata']
        );

        $this->startWorkflow($intent, ProcessBankWithdrawalWorkflow::class, $withdrawal);

        // Return sandbox withdrawal status
        return [
            'reference' => $sandboxData['reference'],
            'status'    => 'processing',
            'intent_id' => $intent->id,
        ];
    }

    /**
     * Process an OpenBanking deposit using bank sandbox APIs.
     * Uses real OAuth flow with sandbox credentials.
     *
     * @return string The persisted payment-intent id (UUID).
     */
    public function processOpenBankingDeposit(array $data): string
    {
        Log::info('Processing sandbox OpenBanking deposit', array_merge($data, [
            'environment' => 'sandbox',
        ]));

        // Add sandbox-specific metadata
        $sandboxData = $data;
        $sandboxData['metadata'] = array_merge($data['metadata'] ?? [], [
            'environment' => 'sandbox',
            'test_mode'   => true,
        ]);

        $intent = $this->intentService()->create([
            'account_uuid'       => $sandboxData['account_uuid'],
            'direction'          => PaymentIntentDirection::INCOMING,
            'kind'               => PaymentIntentKind::DEPOSIT,
            'amount_minor'       => PaymentIntentService::amountToMinor($sandboxData['amount'], (string) $sandboxData['currency']),
            'currency'           => (string) $sandboxData['currency'],
            'provider'           => 'openbanking',
            'provider_reference' => $sandboxData['external_reference'] ?? null,
            'metadata'           => array_merge($sandboxData['metadata'], ['reference' => $sandboxData['reference'] ?? null]),
        ], $this->idempotencyKey('sandbox-openbanking-deposit:', $sandboxData));

        // Use the production workflow with sandbox OpenBanking credentials
        $deposit = new OpenBankingDeposit(
            accountUuid: $sandboxData['account_uuid'],
            amount: $intent->amount_minor,
            currency: $sandboxData['currency'],
            reference: $sandboxData['reference'],
            bankName: $sandboxData['bank_name'],
            metadata: $sandboxData['metadata']
        );

        $this->startWorkflow($intent, ProcessOpenBankingDepositWorkflow::class, $deposit);

        return $intent->id;
    }

    /**
     * Start the workflow and move the intent to PROCESSING. A retried call
     * with the same idempotency key does NOT submit again; a failed start
     * FAILS the intent with the reason.
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
