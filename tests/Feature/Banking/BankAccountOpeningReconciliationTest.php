<?php

declare(strict_types=1);

use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankBalance;
use App\Domain\Banking\Models\BankCapabilities;
use App\Domain\Banking\Models\ExternalBankAccountApplication;
use App\Domain\Banking\Services\BankAccountOpeningService;
use App\Domain\Compliance\Services\KycService;
use App\Models\User;
use Carbon\Carbon;

function reconKycUser(): User
{
    $user = User::factory()->create();
    $user->kyc_status = 'approved';
    $user->kyc_expires_at = null;
    $user->save();

    return $user;
}

function reconService(?AccountProviderInterface $provider = null): BankAccountOpeningService
{
    return new BankAccountOpeningService($provider ?? new SandboxBankAdapter(), new KycService());
}

function reconDetails(array $overrides = []): array
{
    return array_merge([
        'account_type' => 'current',
        'currency'     => 'EUR',
        'holder_name'  => 'Jane Smith',
    ], $overrides);
}

/**
 * Provider that reports "approved" from getAccountStatus but returns a
 * FROZEN account from getAccount — the two must never disagree into a
 * fake approval.
 */
final class DisagreeingProviderStub implements AccountProviderInterface
{
    public function __construct(private readonly BankAccount $account)
    {
    }

    public function getName(): string
    {
        return 'disagreeing-stub';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getCapabilities(): BankCapabilities
    {
        return new BankCapabilities(supportedCurrencies: ['EUR'], supportedTransferTypes: [], features: ['account_opening'], limits: [], fees: []);
    }

    public function openAccount(string $userId, array $accountDetails, string $idempotencyKey): BankAccount
    {
        return $this->account;
    }

    public function getAccount(string $externalAccountId): BankAccount
    {
        return $this->account;
    }

    public function closeAccount(string $externalAccountId, string $reason, string $idempotencyKey): bool
    {
        return true;
    }

    public function freezeAccount(string $externalAccountId, string $reason): bool
    {
        return true;
    }

    public function unfreezeAccount(string $externalAccountId): bool
    {
        return true;
    }

    public function getBalance(string $externalAccountId, string $currency): BankBalance
    {
        return new BankBalance($externalAccountId, $currency, '0.00', '0.00', '0.0000', '0.0000', Carbon::now());
    }

    public function getAccountStatus(string $externalAccountId): array
    {
        return ['status' => 'approved', 'provider_reference' => $externalAccountId];
    }
}

it('reconciles a pending application to approved once the provider approves it', function (): void {
    $user = reconKycUser();
    $service = reconService();

    $application = $service->requestOpening($user, reconDetails(['sandbox_outcome' => 'pending_then_approved']), 'recon-key-1');
    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_PENDING);

    // First reconciliation pass: provider still reports pending.
    $first = $service->reconcilePendingApplications(limit: 50);
    expect($first['pending'])->toBe(1)
        ->and($first['approved'])->toBe(0);

    // Second pass: the sandbox flips to approved; the application follows.
    $second = $service->reconcilePendingApplications(limit: 50);
    expect($second['approved'])->toBe(1);

    $application->refresh();
    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_APPROVED)
        ->and($application->external_id)->not->toBeNull();
});

it('reconciliation leaves an unknown provider outcome in manual review, never approved', function (): void {
    $user = reconKycUser();
    $service = reconService();

    $application = $service->requestOpening($user, reconDetails(['sandbox_outcome' => 'pending_then_approved']), 'recon-key-2');

    // Corrupt the sandbox record so the status query fails: delete the
    // cached account by pointing the application at a missing external id.
    $application->external_id = 'sbx_missing';
    $application->save();

    $summary = $service->reconcilePendingApplications(limit: 50);

    expect($summary['manual_review'])->toBe(1);
    expect($application->fresh()->status)->toBe(ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW);
});

it('syncStatus never approves when the provider account itself is not active', function (): void {
    $frozen = new BankAccount(
        id: 'stub-frozen-1',
        bankCode: 'STUB',
        accountNumber: 'STUB1',
        iban: 'DE89370400440532013000',
        swift: 'STUBDEFF',
        currency: 'EUR',
        accountType: 'current',
        status: 'frozen',
        holderName: 'Jane Smith',
        holderAddress: null,
        metadata: [],
        createdAt: Carbon::now(),
        updatedAt: Carbon::now(),
        closedAt: null,
    );

    $service = reconService(new DisagreeingProviderStub($frozen));
    $user = reconKycUser();

    // Open through the stub: the stub returns the frozen account, so the
    // application must land in manual review, not approved.
    $application = $service->requestOpening($user, reconDetails(), 'recon-key-3');
    expect($application->status)->toBe(ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW);

    // And a later sync must not flip it to approved either.
    $synced = $service->syncStatus((string) $application->id);
    expect($synced->status)->toBe(ExternalBankAccountApplication::STATUS_REQUIRES_MANUAL_REVIEW);
});
