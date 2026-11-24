<?php

declare(strict_types=1);

use App\Domain\Banking\Contracts\IBankConnector;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankAccountModel;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankCapabilities;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankConnectionModel;
use App\Domain\Banking\Models\BankStatement;
use App\Domain\Banking\Models\BankTransfer;
use App\Domain\Banking\Services\BankHealthMonitor;
use App\Domain\Banking\Services\BankIntegrationService;
use App\Domain\Banking\Services\BankRoutingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fake connector that records the DB transaction depth at the moment each
 * network-style call runs. A provider call made inside a DB transaction
 * holds locks while waiting on the network — the service must call the
 * provider OUTSIDE any transaction (depth 0).
 */
final class RecordingFakeConnector implements IBankConnector
{
    public int $authenticateDepth = -1;

    public int $createAccountDepth = -1;

    public int $initiateTransferDepth = -1;

    public int $getBalanceDepth = -1;

    /** @var array<string, string> accountId => available balance (exact decimal string) */
    public array $balances = [];

    public function getBankCode(): string
    {
        return 'FAKE';
    }

    public function getBankName(): string
    {
        return 'Fake Bank';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getCapabilities(): BankCapabilities
    {
        return new BankCapabilities(
            supportedCurrencies: ['EUR', 'USD'],
            supportedTransferTypes: ['SEPA', 'SWIFT', 'INTERNAL'],
            features: ['account_opening', 'account_closure'],
            limits: [],
            fees: [],
        );
    }

    public function authenticate(): void
    {
        $this->authenticateDepth = DB::transactionLevel();
    }

    public function createAccount(array $accountDetails): BankAccount
    {
        $this->createAccountDepth = DB::transactionLevel();

        return $this->fakeAccount($accountDetails['external_id'] ?? 'fake-acc-1');
    }

    public function getAccount(string $accountId): BankAccount
    {
        return $this->fakeAccount($accountId);
    }

    public function getBalance(string $accountId, ?string $currency = null): BankBalance|Collection
    {
        $this->getBalanceDepth = DB::transactionLevel();
        $available = $this->balances[$accountId] ?? '100.00';

        return new BankBalance(
            accountId: $accountId,
            currency: $currency ?? 'EUR',
            available: $available,
            current: $available,
            pending: '0.0000',
            reserved: '0.0000',
            asOf: Carbon::now(),
        );
    }

    public function initiateTransfer(array $transferDetails): BankTransfer
    {
        $this->initiateTransferDepth = DB::transactionLevel();

        return new BankTransfer(
            id: 'tr_' . uniqid(),
            bankCode: 'FAKE',
            type: $transferDetails['type'] ?? 'SWIFT',
            status: 'completed',
            fromAccountId: $transferDetails['from_account_id'],
            toAccountId: $transferDetails['to_account_id'],
            toBankCode: $transferDetails['to_bank_code'] ?? 'FAKE',
            amount: (string) $transferDetails['amount'],
            currency: $transferDetails['currency'],
            reference: $transferDetails['reference'] ?? null,
            description: $transferDetails['description'] ?? null,
            fees: [],
            exchangeRate: [],
            createdAt: Carbon::now(),
            updatedAt: Carbon::now(),
            executedAt: Carbon::now(),
            failedAt: null,
            failureReason: null,
        );
    }

    public function getTransferStatus(string $transferId): BankTransfer
    {
        throw new RuntimeException('not implemented in fake');
    }

    public function cancelTransfer(string $transferId): bool
    {
        return true;
    }

    public function getTransactions(string $accountId, DateTime $from, DateTime $to, int $limit = 100): Collection
    {
        return collect();
    }

    public function getStatement(string $accountId, DateTime $from, DateTime $to, string $format = 'JSON'): BankStatement
    {
        throw new RuntimeException('not implemented in fake');
    }

    public function validateIBAN(string $iban): bool
    {
        return true;
    }

    public function getSupportedCurrencies(): array
    {
        return ['EUR', 'USD'];
    }

    public function getTransferLimits(string $accountId, string $transferType): array
    {
        return [];
    }

    public function verifyWebhookSignature(string $payload, string $signature, array $headers): bool
    {
        return true;
    }

    public function processWebhook(string $payload): array
    {
        return [];
    }

    private function fakeAccount(string $id): BankAccount
    {
        return new BankAccount(
            id: $id,
            bankCode: 'FAKE',
            accountNumber: 'FAKE123456',
            iban: 'DE89370400440532013000',
            swift: 'FAKEDEFFXXX',
            currency: 'EUR',
            accountType: 'current',
            status: 'active',
            holderName: 'Jane Doe',
            holderAddress: null,
            metadata: [],
            createdAt: Carbon::now(),
            updatedAt: Carbon::now(),
            closedAt: null,
        );
    }
}

function hardeningService(): BankIntegrationService
{
    $monitor = new BankHealthMonitor();

    return new BankIntegrationService($monitor, new BankRoutingService($monitor));
}

function registerFake(BankIntegrationService $service, RecordingFakeConnector $connector, string $code = 'FAKE'): void
{
    // The fake reports its own code; register under the requested code by
    // wrapping is not needed — the service keys by the given bank code.
    $service->registerConnector($code, $connector);
}

it('connects a user without calling the provider inside a DB transaction', function (): void {
    $user = User::factory()->create();
    $service = hardeningService();
    $connector = new RecordingFakeConnector();
    registerFake($service, $connector);

    $baseline = DB::transactionLevel();
    $connection = $service->connectUserToBank($user, 'FAKE', ['token' => 'secret']);

    expect($connector->authenticateDepth)->toBe($baseline)
        ->and($connection->isActive())->toBeTrue()
        ->and($connection->bankCode)->toBe('FAKE')
        ->and(BankConnectionModel::query()->where('user_uuid', $user->uuid)->count())->toBe(1);
});

it('creates a bank account without calling the provider inside a DB transaction', function (): void {
    $user = User::factory()->create();
    $service = hardeningService();
    $connector = new RecordingFakeConnector();
    registerFake($service, $connector);

    BankConnectionModel::create([
        'id'          => (string) Str::uuid(),
        'user_uuid'   => $user->uuid,
        'bank_code'   => 'FAKE',
        'status'      => 'active',
        'credentials' => encrypt(['token' => 'secret']),
        'permissions' => ['accounts'],
        'expires_at'  => now()->addMonths(3),
    ]);

    $baseline = DB::transactionLevel();
    $account = $service->createBankAccount($user, 'FAKE', ['external_id' => 'fake-acc-1']);

    expect($connector->createAccountDepth)->toBe($baseline)
        ->and($account->id)->toBe('fake-acc-1')
        ->and(BankAccountModel::query()->where('external_id', 'fake-acc-1')->count())->toBe(1);
});

it('initiates an inter-bank transfer with an exact decimal-string amount and no provider call inside a transaction', function (): void {
    $user = User::factory()->create();
    $service = hardeningService();
    $connector = new RecordingFakeConnector();
    $connector->balances['acc-from'] = '100.00';
    registerFake($service, $connector, 'FAKE');
    registerFake($service, new RecordingFakeConnector(), 'FAKE2');

    BankAccountModel::create([
        'id'             => (string) Str::uuid(),
        'user_uuid'      => $user->uuid,
        'bank_code'      => 'FAKE',
        'external_id'    => 'acc-from',
        'account_number' => encrypt('FAKE123456'),
        'iban'           => encrypt('DE89370400440532013000'),
        'currency'       => 'EUR',
        'account_type'   => 'current',
        'status'         => 'active',
    ]);

    $baseline = DB::transactionLevel();
    $transfer = $service->initiateInterBankTransferExact($user, 'FAKE', 'acc-from', 'FAKE2', 'acc-to', '10.10', 'EUR');

    expect($connector->getBalanceDepth)->toBe($baseline)
        ->and($connector->initiateTransferDepth)->toBe($baseline)
        ->and($transfer->amount)->toBe('10.1000')
        ->and(DB::table('bank_transfers')->where('from_account_id', 'acc-from')->count())->toBe(1);
});

it('aggregates balances exactly: 0.10 + 0.20 is 0.30, not a float artefact', function (): void {
    $user = User::factory()->create();
    $service = hardeningService();

    $connectorA = new RecordingFakeConnector();
    $connectorA->balances['acc-a'] = '0.10';
    $connectorB = new RecordingFakeConnector();
    $connectorB->balances['acc-b'] = '0.20';
    registerFake($service, $connectorA, 'FAKE');
    registerFake($service, $connectorB, 'FAKE2');

    foreach (['FAKE' => 'acc-a', 'FAKE2' => 'acc-b'] as $code => $externalId) {
        BankConnectionModel::create([
            'id'          => (string) Str::uuid(),
            'user_uuid'   => $user->uuid,
            'bank_code'   => $code,
            'status'      => 'active',
            'credentials' => encrypt(['token' => 'secret']),
            'permissions' => ['accounts'],
            'expires_at'  => now()->addMonths(3),
        ]);
        BankAccountModel::create([
            'id'             => (string) Str::uuid(),
            'user_uuid'      => $user->uuid,
            'bank_code'      => $code,
            'external_id'    => $externalId,
            'account_number' => encrypt('FAKE123456'),
            'iban'           => encrypt('DE89370400440532013000'),
            'swift'          => 'FAKEDEFFXXX',
            'currency'       => 'EUR',
            'account_type'   => 'current',
            'status'         => 'active',
        ]);
    }

    expect($service->getAggregatedBalanceExact($user, 'EUR'))->toBe('0.3000');
});

it('BankConnection::fromArray accepts the model user_uuid key', function (): void {
    $user = User::factory()->create();

    $model = BankConnectionModel::create([
        'id'          => (string) Str::uuid(),
        'user_uuid'   => $user->uuid,
        'bank_code'   => 'FAKE',
        'status'      => 'active',
        'credentials' => encrypt(['token' => 'secret']),
        'permissions' => ['accounts'],
        'expires_at'  => now()->addMonths(3),
    ]);

    $connection = BankConnection::fromArray($model->toArray());

    expect($connection->userId)->toBe($user->uuid)
        ->and($connection->isActive())->toBeTrue();
});
