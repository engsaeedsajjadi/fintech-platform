<?php

declare(strict_types=1);

use App\Domain\Banking\Connectors\OpenBankingConnector;
use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Exceptions\AccountOpeningException;
use App\Domain\Banking\Exceptions\BankOperationException;
use App\Domain\Banking\Models\BankAccountModel;
use App\Domain\Banking\Models\BankCapabilities;
use App\Domain\Banking\Models\ExternalBankAccountApplication;
use App\Domain\Banking\Services\BankAccountOpeningService;
use App\Domain\Banking\Services\BankAccountProviderFactory;
use App\Domain\Compliance\Services\KycService;
use App\Models\User;
use Mockery;

function kycApprovedUser(): User
{
    $user = User::factory()->create();
    $user->kyc_status = 'approved';
    $user->kyc_expires_at = null;
    $user->save();

    return $user;
}

function kycPendingUser(): User
{
    $user = User::factory()->create();
    $user->kyc_status = 'pending';
    $user->kyc_expires_at = null;
    $user->save();

    return $user;
}

function openingService(?AccountProviderInterface $provider = null): BankAccountOpeningService
{
    return new BankAccountOpeningService($provider ?? new SandboxBankAdapter(), new KycService());
}

function openingDetails(array $overrides = []): array
{
    return array_merge([
        'account_type' => 'current',
        'currency' => 'EUR',
        'holder_name' => 'Jane Smith',
        'holder_address' => '1 Test Street, Berlin',
    ], $overrides);
}

it('opens an account via the sandbox provider with number and IBAN from the provider response', function (): void {
    $user = kycApprovedUser();

    $application = openingService()->requestOpening($user, openingDetails(), 'key-open-1');

    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_APPROVED)
        ->and($application->external_id)->toStartWith('sbx_')
        ->and($application->provider)->toBe('sandbox');

    // The persisted account carries exactly what the provider returned —
    // the workflow generates no numbers itself.
    $stored = BankAccountModel::query()->where('external_id', $application->external_id)->firstOrFail();
    expect($stored->status)->toBe('active')
        ->and($stored->currency)->toBe('EUR');

    $providerAccount = (new SandboxBankAdapter())->getAccount((string) $application->external_id);
    expect(decrypt($stored->account_number))->toBe($providerAccount->accountNumber)
        ->and(decrypt($stored->iban))->toBe($providerAccount->iban)
        ->and($providerAccount->metadata['sandbox'])->toBeTrue();

    expect((new SandboxBankAdapter())->validateIBAN($providerAccount->iban))->toBeTrue();
    expect($providerAccount->accountNumber)->toStartWith('SBX');
});

it('returns the same application for a duplicate request with the same idempotency key', function (): void {
    $user = kycApprovedUser();
    $service = openingService();

    $first = $service->requestOpening($user, openingDetails(), 'key-dup-1');
    $second = $service->requestOpening($user, openingDetails(), 'key-dup-1');

    expect($second->id)->toBe($first->id)
        ->and(ExternalBankAccountApplication::query()->where('idempotency_key', 'key-dup-1')->count())->toBe(1)
        ->and(BankAccountModel::query()->where('external_id', $first->external_id)->count())->toBe(1);
});

it('refuses to open an account when KYC is not approved', function (): void {
    $user = kycPendingUser();

    openingService()->requestOpening($user, openingDetails(), 'key-kyc-1');
})->throws(AccountOpeningException::class, 'requires approved KYC');

it('leaves no application row when the KYC gate refuses', function (): void {
    $user = kycPendingUser();

    try {
        openingService()->requestOpening($user, openingDetails(), 'key-kyc-2');
        $this->fail('Expected an AccountOpeningException.');
    } catch (AccountOpeningException) {
        // Expected — the assertion below is the point.
    }

    expect(ExternalBankAccountApplication::query()->where('idempotency_key', 'key-kyc-2')->count())->toBe(0)
        ->and(BankAccountModel::query()->count())->toBe(0);
});

it('records a provider rejection without creating a bank account', function (): void {
    $user = kycApprovedUser();

    try {
        openingService()->requestOpening($user, openingDetails([
            'sandbox_outcome' => 'rejected',
            'sandbox_rejection_reason' => 'simulated credit check failure',
        ]), 'key-rej-1');
        $this->fail('Expected an AccountOpeningException.');
    } catch (AccountOpeningException $e) {
        expect($e->getMessage())->toContain('rejected');
    }

    $application = ExternalBankAccountApplication::query()->where('idempotency_key', 'key-rej-1')->firstOrFail();
    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_REJECTED)
        ->and($application->rejection_reason)->toContain('simulated credit check failure')
        ->and($application->external_id)->toBeNull()
        ->and(BankAccountModel::query()->count())->toBe(0);
});

it('tracks a pending application to approved via status sync', function (): void {
    $user = kycApprovedUser();
    $service = openingService();

    $application = $service->requestOpening($user, openingDetails([
        'sandbox_outcome' => 'pending_then_approved',
    ]), 'key-pend-1');

    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_PENDING)
        ->and($application->external_id)->toStartWith('sbx_')
        ->and(BankAccountModel::query()->where('external_id', $application->external_id)->count())->toBe(0);

    // First sync: provider still reports pending.
    $synced = $service->syncStatus((string) $application->id);
    expect($synced->status)->toBe(ExternalBankAccountApplication::STATUS_PENDING);

    // Second sync: provider reports approved — account is fetched from the
    // provider and persisted.
    $synced = $service->syncStatus((string) $application->id);
    expect($synced->status)->toBe(ExternalBankAccountApplication::STATUS_APPROVED)
        ->and(BankAccountModel::query()->where('external_id', $application->external_id)->count())->toBe(1);
});

it('retries a rejected application with the same idempotency key', function (): void {
    $user = kycApprovedUser();
    $service = openingService();

    try {
        $service->requestOpening($user, openingDetails(['sandbox_outcome' => 'rejected']), 'key-retry-1');
    } catch (AccountOpeningException) {
        // Expected.
    }

    $application = ExternalBankAccountApplication::query()->where('idempotency_key', 'key-retry-1')->firstOrFail();
    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_REJECTED);

    $retried = $service->retryOpening((string) $application->id);
    expect($retried->status)->toBe(ExternalBankAccountApplication::STATUS_APPROVED)
        ->and($retried->idempotency_key)->toBe('key-retry-1')
        ->and(BankAccountModel::query()->where('external_id', $retried->external_id)->count())->toBe(1);
});

it('retrieves balance and statement from the provider', function (): void {
    $user = kycApprovedUser();
    $service = openingService();
    $application = $service->requestOpening($user, openingDetails(), 'key-bal-1');

    $balance = $service->getBalance((string) $application->id);
    expect($balance->accountId)->toBe((string) $application->external_id)
        ->and($balance->currency)->toBe('EUR')
        ->and($balance->available)->toBe('0.00')
        ->and($balance->metadata['sandbox'])->toBeTrue();

    $statement = $service->getStatement(
        (string) $application->id,
        new DateTime('2026-11-01'),
        new DateTime('2026-11-24'),
    );
    expect($statement->accountId)->toBe((string) $application->external_id)
        ->and($statement->transactions->count())->toBe(0)
        ->and($statement->openingBalance)->toBe('0.00');
});

it('closes an account through the provider when closure is supported', function (): void {
    $user = kycApprovedUser();
    $service = openingService();
    $application = $service->requestOpening($user, openingDetails(), 'key-close-1');

    $closed = $service->requestClosure((string) $application->id, 'user request');

    expect($closed->status)->toBe(ExternalBankAccountApplication::STATUS_CLOSED)
        ->and(BankAccountModel::query()->where('external_id', $application->external_id)->value('status'))->toBe('closed')
        ->and((new SandboxBankAdapter())->getAccountStatus((string) $application->external_id)['status'])->toBe('closed');
});

it('fails closure explicitly when the provider does not support it', function (): void {
    $user = kycApprovedUser();

    $provider = Mockery::mock(AccountProviderInterface::class);
    $provider->shouldReceive('getName')->andReturn('mock');
    $provider->shouldReceive('getCapabilities')->andReturn(new BankCapabilities(
        supportedCurrencies: ['EUR'],
        supportedTransferTypes: [],
        features: [],
        limits: [],
        fees: [],
        supportsInstantTransfers: false,
        supportsScheduledTransfers: false,
        supportsBulkTransfers: false,
        supportsDirectDebits: false,
        supportsStandingOrders: false,
        supportsVirtualAccounts: false,
        supportsMultiCurrency: false,
        supportsWebhooks: false,
        supportsStatements: false,
        supportsCardIssuance: false,
        maxAccountsPerUser: 1,
        requiredDocuments: [],
        availableCountries: [],
    ));

    $application = new ExternalBankAccountApplication();
    $application->user_uuid = $user->uuid;
    $application->bank_code = 'MOCK';
    $application->provider = 'mock';
    $application->account_type = 'current';
    $application->currency = 'EUR';
    $application->status = ExternalBankAccountApplication::STATUS_APPROVED;
    $application->idempotency_key = 'key-close-2';
    $application->external_id = 'mock-1';
    $application->save();

    openingService($provider)->requestClosure((string) $application->id, 'user request');
})->throws(AccountOpeningException::class, 'does not support account closure');

it('fails closed for the openbanking provider, which cannot open accounts', function (): void {
    (new BankAccountProviderFactory())->make('openbanking');
})->throws(AccountOpeningException::class, 'does not support account opening');

it('resolves the sandbox provider from the container by default', function (): void {
    expect($this->app->make(AccountProviderInterface::class))->toBeInstanceOf(SandboxBankAdapter::class);
});

it('refuses to construct the sandbox adapter in production', function (): void {
    $this->app->detectEnvironment(fn () => 'production');

    new SandboxBankAdapter();
})->throws(RuntimeException::class, 'must never run in production');

it('makes OpenBanking createAccount fail with an explicit capability error', function (): void {
    $connector = new OpenBankingConnector(['bank_code' => 'TEST', 'bank_name' => 'Test Bank']);

    $connector->createAccount(['holder_name' => 'Jane Smith']);
})->throws(BankOperationException::class, 'account_opening=false');

it('validates sandbox IBANs with the real mod-97 algorithm', function (): void {
    $adapter = new SandboxBankAdapter();

    expect($adapter->validateIBAN('DE89370400440532013000'))->toBeTrue()
        ->and($adapter->validateIBAN('DE89370400440532013001'))->toBeFalse()
        ->and($adapter->validateIBAN('not-an-iban'))->toBeFalse();
});
