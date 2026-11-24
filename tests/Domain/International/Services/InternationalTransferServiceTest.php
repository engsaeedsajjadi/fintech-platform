<?php

declare(strict_types=1);

use App\Domain\International\Contracts\InternationalScreeningInterface;
use App\Domain\International\Contracts\ScreeningResult;
use App\Domain\International\Enums\InternationalTransferStatus;
use App\Domain\International\Enums\ScreeningOutcome;
use App\Domain\International\Exceptions\InternationalRailNotAvailableException;
use App\Domain\International\Exceptions\InternationalTransferException;
use App\Domain\International\Exceptions\InvalidTransferTransitionException;
use App\Domain\International\Models\Beneficiary;
use App\Domain\International\Models\Counterparty;
use App\Domain\International\Models\InternationalAccount;
use App\Domain\International\Models\InternationalTransfer;
use App\Domain\International\Services\InternationalAccountService;
use App\Domain\International\Services\InternationalAuditService;
use App\Domain\International\Services\InternationalScreeningService;
use App\Domain\International\Services\InternationalTransferService;
use App\Domain\International\Services\QuotationService;
use App\Domain\International\Services\TransferFeeService;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Payment\Services\TransferFeeCalculator;
use App\Models\User;

beforeEach(function (): void {
    config([
        'international.fee_basis_points' => 0,
        'international.fee_fixed_minor'  => 0,
    ]);

    $this->audit = new InternationalAuditService();
    $this->accounts = new InternationalAccountService($this->audit);
    $this->ledger = new LedgerService(new EloquentDriver());
    $this->quotations = new QuotationService($this->audit);
    $this->fees = new TransferFeeService(new TransferFeeCalculator());
});

function intlService(?InternationalScreeningInterface $adapter = null): InternationalTransferService
{
    $screening = new InternationalScreeningService(new InternationalAuditService());
    if ($adapter !== null) {
        $screening->setAdapter($adapter);
    }

    return new InternationalTransferService(
        new LedgerService(new EloquentDriver()),
        new TransferFeeService(new TransferFeeCalculator()),
        new QuotationService(new InternationalAuditService()),
        $screening,
        new InternationalAuditService(),
        new InternationalAccountService(new InternationalAuditService()),
    );
}

/** @return array{0: InternationalAccount, 1: Counterparty, 2: Beneficiary} */
function intlFixtures(User $user): array
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
        'bank_name'       => 'Example Bank',
        'bic'             => 'EXBKDEFF',
        'iban_last4'      => '1234',
        'account_last4'   => null,
        'currency'        => 'USD',
        'is_active'       => true,
    ]);

    return [$account, $counterparty, $beneficiary];
}

/** @return array{international_account_id: string, counterparty_id: string, beneficiary_id: string, amount_minor: int, currency: string, destination_currency: string, purpose: string, rail: string} */
function intlTransferData(
    InternationalAccount $account,
    Counterparty $counterparty,
    Beneficiary $beneficiary,
    int $amountMinor = 10000,
): array {
    return [
        'international_account_id' => $account->id,
        'counterparty_id'          => $counterparty->id,
        'beneficiary_id'           => $beneficiary->id,
        'amount_minor'             => $amountMinor,
        'currency'                 => 'USD',
        'destination_currency'     => 'USD',
        'purpose'                  => 'family_support',
        'rail'                     => 'swift',
    ];
}

function intlFundAccount(LedgerService $ledger, InternationalAccount $account, string $amount): void
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

final class FakeClearScreeningAdapter implements InternationalScreeningInterface
{
    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult
    {
        return new ScreeningResult(ScreeningOutcome::CLEAR, 'fake-clear', 0, 'fake clear');
    }

    public function getName(): string
    {
        return 'fake-clear';
    }
}

final class FakeHitScreeningAdapter implements InternationalScreeningInterface
{
    public function screen(Counterparty $counterparty, Beneficiary $beneficiary): ScreeningResult
    {
        return new ScreeningResult(ScreeningOutcome::HIT, 'fake-hit', 2, 'fake hit');
    }

    public function getName(): string
    {
        return 'fake-hit';
    }
}

it('creates a draft transfer with references and an idempotency key', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);

    $transfer = intlService()->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-1');

    expect($transfer->status)->toBe(InternationalTransferStatus::DRAFT)
        ->and($transfer->reference)->toStartWith('INTL-')
        ->and($transfer->end_to_end_reference)->toStartWith('E2E-')
        ->and($transfer->amount_minor)->toBe(10000)
        ->and($transfer->fee_minor)->toBe(0)
        ->and($transfer->statusHistory)->toHaveCount(1);
});

it('replays the same idempotency key with the same payload instead of duplicating', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService();

    $first = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-replay');
    $second = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-replay');

    expect($second->id)->toBe($first->id)
        ->and(InternationalTransfer::count())->toBe(1);
});

it('refuses a replayed idempotency key with a different payload', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService();

    $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-conflict');

    $different = intlTransferData($account, $counterparty, $beneficiary, 99999);

    expect(fn () => $service->createTransfer($this->user, $different, 'key-conflict'))
        ->toThrow(InternationalTransferException::class, 'different payload');

    expect(InternationalTransfer::count())->toBe(1);
});

it('validates purpose, cross-currency quotation and beneficiary currency at creation', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService();

    $badPurpose = intlTransferData($account, $counterparty, $beneficiary);
    $badPurpose['purpose'] = 'other';
    expect(fn () => $service->createTransfer($this->user, $badPurpose, 'k-bad-purpose'))
        ->toThrow(InvalidArgumentException::class, 'purpose detail');

    $crossCurrency = intlTransferData($account, $counterparty, $beneficiary);
    $crossCurrency['destination_currency'] = 'EUR';
    expect(fn () => $service->createTransfer($this->user, $crossCurrency, 'k-no-quote'))
        ->toThrow(InvalidArgumentException::class, 'Beneficiary currency');

    $crossCurrency['exchange_quotation_id'] = 'missing';
    expect(fn () => $service->createTransfer($this->user, $crossCurrency, 'k-no-quote-2'))
        ->toThrow(InvalidArgumentException::class, 'Beneficiary currency');
});

it('runs the full happy path and posts balanced ledger legs', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());
    intlFundAccount($this->ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-happy');
    $transfer = $service->submit($transfer, $this->user);
    expect($transfer->status)->toBe(InternationalTransferStatus::SUBMITTED);

    // Fake clear adapter approves straight out of screening.
    $transfer = $service->beginScreening($transfer, $this->user);
    expect($transfer->status)->toBe(InternationalTransferStatus::APPROVED);

    $transfer = $service->process($transfer, $this->user);
    expect($transfer->status)->toBe(InternationalTransferStatus::PROCESSING)
        ->and($transfer->journal_entry_id)->not->toBeNull()
        ->and($transfer->settlementInstructions)->toHaveCount(1);

    // Sender funded 1000, reserved 100 → balance 900. Clearing holds 100.
    expect($this->accounts->balance($account->refresh()))->toBe('900.0000');

    $transfer = $service->settle($transfer, $this->user, 'PROVIDER-REF-1');
    expect($transfer->status)->toBe(InternationalTransferStatus::SETTLED)
        ->and($transfer->external_reference)->toBe('PROVIDER-REF-1')
        ->and($transfer->settlement_journal_entry_id)->not->toBeNull();

    // Clearing is empty again; settled account holds the 100.
    $totals = $this->accounts->postedTotals('intl:clearing:USD');
    expect($totals['credit'])->toBe('100.0000')
        ->and($totals['debit'])->toBe('100.0000');

    // Full status history: draft, submitted, screening, approved, processing, settled.
    expect($transfer->statusHistory()->count())->toBe(6);
});

it('refuses to process when the sender cannot cover the amount', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());
    intlFundAccount($this->ledger, $account, '50.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-poor');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);

    expect(fn () => $service->process($transfer, $this->user))->toThrow(RuntimeException::class, 'overdraw');
    expect($transfer->refresh()->status)->toBe(InternationalTransferStatus::APPROVED)
        ->and($transfer->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe(1); // only the funding entry
});

it('posts the fee as a separate ledger entry', function (): void {
    config(['international.fee_basis_points' => 100, 'international.fee_fixed_minor' => 0]);

    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());
    intlFundAccount($this->ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-fee');
    expect($transfer->fee_minor)->toBe(100); // 1% of 10000 minor

    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);
    $transfer = $service->process($transfer, $this->user);

    expect($transfer->fee_journal_entry_id)->not->toBeNull()
        ->and($transfer->fee_journal_entry_id)->not->toBe($transfer->journal_entry_id);

    // Sender paid 100 principal + 1 fee → 899 left.
    expect($this->accounts->balance($account->refresh()))->toBe('899.0000');
});

it('dispatch to a disabled rail fails the transfer and returns the funds', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());
    intlFundAccount($this->ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-dispatch');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);
    $transfer = $service->process($transfer, $this->user);

    expect(fn () => $service->attemptExternalDispatch($transfer, $this->user))
        ->toThrow(InternationalRailNotAvailableException::class, 'not available');

    $transfer = $transfer->refresh();
    expect($transfer->status)->toBe(InternationalTransferStatus::FAILED)
        ->and($transfer->failure_reason)->toContain('not available')
        ->and($transfer->settlementInstructions()->first()->status->value)->toBe('blocked');

    // Funds are back: the mirror reversal nets the reservation out.
    expect($this->accounts->balance($account->refresh()))->toBe('1000.0000');
});

it('reverses a processing transfer and returns the funds', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());
    intlFundAccount($this->ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-reverse');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);
    $transfer = $service->process($transfer, $this->user);
    $transfer = $service->reverse($transfer, $this->user, 'sender cancelled');

    expect($transfer->status)->toBe(InternationalTransferStatus::REVERSED);
    expect($this->accounts->balance($account->refresh()))->toBe('1000.0000');
});

it('rejects invalid state transitions', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeClearScreeningAdapter());

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-transitions');

    expect(fn () => $service->process($transfer, $this->user))
        ->toThrow(InvalidTransferTransitionException::class);

    expect(fn () => $service->settle($transfer, $this->user, 'X'))
        ->toThrow(InvalidTransferTransitionException::class);

    $transfer = $service->submit($transfer, $this->user);
    expect(fn () => $service->submit($transfer, $this->user))
        ->toThrow(InvalidTransferTransitionException::class);
});

it('keeps a transfer in screening with the manual-review default until an officer decides', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(); // no adapter → manual review

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-manual');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);

    expect($transfer->status)->toBe(InternationalTransferStatus::SCREENING)
        ->and($transfer->screening_status)->toBe('manual_review_required');

    $transfer = $service->recordScreeningDecision($transfer, $this->user, 'clear', 'officer reviewed documents');
    expect($transfer->status)->toBe(InternationalTransferStatus::APPROVED);
});

it('auto-rejects a transfer on a screening hit', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService(new FakeHitScreeningAdapter());

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-hit');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);

    expect($transfer->status)->toBe(InternationalTransferStatus::REJECTED);
});

it('requires a reason to reject at screening and an external reference to settle', function (): void {
    [$account, $counterparty, $beneficiary] = intlFixtures($this->user);
    $service = intlService();
    intlFundAccount($this->ledger, $account, '1000.0000');

    $transfer = $service->createTransfer($this->user, intlTransferData($account, $counterparty, $beneficiary), 'key-reasons');
    $transfer = $service->submit($transfer, $this->user);
    $transfer = $service->beginScreening($transfer, $this->user);

    expect(fn () => $service->recordScreeningDecision($transfer, $this->user, 'reject'))
        ->toThrow(InvalidArgumentException::class, 'reason is required');

    $transfer = $service->recordScreeningDecision($transfer, $this->user, 'clear', 'ok');
    $transfer = $service->process($transfer, $this->user);

    expect(fn () => $service->settle($transfer, $this->user, ''))
        ->toThrow(InvalidArgumentException::class, 'external settlement reference');
});
