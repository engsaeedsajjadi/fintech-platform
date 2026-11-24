<?php

declare(strict_types=1);

use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Contracts\ScreeningResult;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use App\Domain\International\Models\InternationalAccount;
use App\Domain\International\Models\InternationalReconciliationReport;
use App\Domain\International\Services\InternationalAccountService;
use App\Domain\International\Services\InternationalAuditService;
use App\Domain\International\Services\InternationalReconciliationService;
use App\Domain\International\Services\InternationalScreeningService;
use App\Domain\International\Services\InternationalTransferService;
use App\Domain\International\Services\QuotationService;
use App\Domain\International\Services\TransferFeeService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Payment\Services\TransferFeeCalculator;
use App\Models\User;

beforeEach(function (): void {
    $this->ledger = new LedgerService(new EloquentDriver());
    $this->audit = new InternationalAuditService();
    $this->accounts = new InternationalAccountService($this->audit);
    $this->reconciliation = new InternationalReconciliationService($this->accounts, $this->audit);
});

final class ReconClearScreeningAdapter implements InternationalScreeningInterface
{
    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult
    {
        return new ScreeningResult(ScreeningOutcome::CLEAR, 'recon-clear', 0, 'clear');
    }

    public function getName(): string
    {
        return 'recon-clear';
    }
}

/** @return array{0: InternationalAccount, 1: Counterparty, 2: Beneficiary} */
function reconFixtures(User $user): array
{
    $accounts = new InternationalAccountService(new InternationalAuditService());
    $account = $accounts->openAccount($user, 'USD');

    $counterparty = Counterparty::create([
        'user_id'          => $user->id,
        'type'             => 'individual',
        'full_name'        => 'Jane Example',
        'country'          => 'DE',
        'screening_status' => 'not_screened',
    ]);

    $beneficiary = Beneficiary::create([
        'user_id'         => $user->id,
        'counterparty_id' => $counterparty->id,
        'display_name'    => 'Jane Example',
        'country'         => 'DE',
        'currency'        => 'USD',
        'is_active'       => true,
    ]);

    return [$account, $counterparty, $beneficiary];
}

function intlReconService(): InternationalTransferService
{
    $screening = new InternationalScreeningService(new InternationalAuditService());
    $screening->setAdapter(new ReconClearScreeningAdapter());

    return new InternationalTransferService(
        new LedgerService(new EloquentDriver()),
        new TransferFeeService(new TransferFeeCalculator()),
        new QuotationService(new InternationalAuditService()),
        $screening,
        new InternationalAuditService(),
        new InternationalAccountService(new InternationalAuditService()),
    );
}

function reconFund(LedgerService $ledger, InternationalAccount $account, string $amount): void
{
    // Customer funds are a platform liability: funding CREDITS the
    // sender account (asset side is the test funding account).
    LedgerAccount::firstOrCreate(
        ['code' => 'intl:funding:USD'],
        ['name' => 'Test funding', 'type' => 'asset', 'currency' => 'USD'],
    );

    $ledger->post('Test funding', [
        ['account_code' => 'intl:funding:USD', 'debit' => $amount, 'credit' => '0.0000', 'currency' => 'USD'],
        ['account_code' => $account->ledger_account_code, 'debit' => '0.0000', 'credit' => $amount, 'currency' => 'USD'],
    ]);
}

/** @return array{international_account_id: string, counterparty_id: string, beneficiary_id: string, amount_minor: int, currency: string, destination_currency: string, purpose: string, rail: string} */
function reconTransferData(InternationalAccount $account, Counterparty $counterparty, Beneficiary $beneficiary): array
{
    return [
        'international_account_id' => $account->id,
        'counterparty_id'          => $counterparty->id,
        'beneficiary_id'           => $beneficiary->id,
        'amount_minor'             => 10000,
        'currency'                 => 'USD',
        'destination_currency'     => 'USD',
        'purpose'                  => 'family_support',
        'rail'                     => 'swift',
    ];
}

function reconProcessingTransfer(User $user, LedgerService $ledger, string $key): object
{
    [$account, $counterparty, $beneficiary] = reconFixtures($user);
    $service = intlReconService();
    reconFund($ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($user, reconTransferData($account, $counterparty, $beneficiary), $key);
    $transfer = $service->submit($transfer, $user);
    $transfer = $service->beginScreening($transfer, $user);

    return (object) ['service' => $service, 'transfer' => $service->process($transfer, $user)];
}

it('matches the clearing account while a transfer is processing', function (): void {
    $setup = reconProcessingTransfer($this->user, $this->ledger, 'key-recon-1');

    $report = $this->reconciliation->reconcileClearing('USD', now()->subDay(), now(), $this->user);

    expect($report->status)->toBe(InternationalReconciliationReport::STATUS_MATCHED)
        ->and($report->gl_balance)->toBe('100.0000')
        ->and($report->domain_balance)->toBe('100.0000')
        ->and($report->variance)->toBe('0.0000');
});

it('reports a discrepancy when the clearing account does not match open transfers', function (): void {
    $setup = reconProcessingTransfer($this->user, $this->ledger, 'key-recon-2');

    // An unrelated posting into the clearing account breaks the match.
    LedgerAccount::firstOrCreate(
        ['code' => 'intl:funding:USD'],
        ['name' => 'Test funding', 'type' => 'asset', 'currency' => 'USD'],
    );
    $this->ledger->post('Unrelated clearing movement', [
        ['account_code' => 'intl:funding:USD', 'debit' => '5.0000', 'credit' => '0.0000', 'currency' => 'USD'],
        ['account_code' => 'intl:clearing:USD', 'debit' => '0.0000', 'credit' => '5.0000', 'currency' => 'USD'],
    ]);

    $report = $this->reconciliation->reconcileClearing('USD', now()->subDay(), now(), $this->user);

    expect($report->status)->toBe(InternationalReconciliationReport::STATUS_DISCREPANCY)
        ->and($report->gl_balance)->toBe('105.0000')
        ->and($report->domain_balance)->toBe('100.0000')
        ->and($report->variance)->toBe('5.0000');
});

it('matches external statement rows against settled transfers', function (): void {
    $setup = reconProcessingTransfer($this->user, $this->ledger, 'key-recon-3');
    $transfer = $setup->service->settle($setup->transfer, $this->user, 'PROVIDER-REF-9');

    $matched = $this->reconciliation->matchStatement([
        ['reference' => $transfer->reference, 'amount_minor' => 10000, 'currency' => 'USD'],
    ], $this->user);

    expect($matched->status)->toBe(InternationalReconciliationReport::STATUS_MATCHED);

    $withBogus = $this->reconciliation->matchStatement([
        ['reference' => $transfer->reference, 'amount_minor' => 10000, 'currency' => 'USD'],
        ['reference' => 'INTL-UNKNOWN-1', 'amount_minor' => 500, 'currency' => 'USD'],
        ['reference' => $transfer->reference, 'amount_minor' => 9999, 'currency' => 'USD'],
    ], $this->user);

    expect($withBogus->status)->toBe(InternationalReconciliationReport::STATUS_DISCREPANCY)
        ->and($withBogus->details['unmatched_statement_rows'])->toHaveCount(2);
});
