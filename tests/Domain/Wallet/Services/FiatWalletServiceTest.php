<?php

declare(strict_types=1);

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Exceptions\LimitExceededException;
use App\Domain\Wallet\Models\FiatDeposit;
use App\Domain\Wallet\Models\FiatTransfer;
use App\Domain\Wallet\Models\FiatWithdrawal;
use App\Domain\Wallet\Services\FiatWalletService;
use App\Domain\Wallet\Services\WalletLimitsService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->service = new FiatWalletService(app(LedgerService::class), new WalletLimitsService());
    $this->accountUuid = $this->account->uuid;
    $this->otherAccountUuid = Str::uuid()->toString();
});

describe('fiat deposits', function () {
    it('posts a balanced ledger entry and the balance comes from the ledger', function () {
        $deposit = $this->service->deposit($this->accountUuid, 'USD', '100.50', 'dep-1', 'prov-ref-1', 'user-a');

        expect($deposit->status)->toBe(FiatDeposit::STATUS_COMPLETED)
            ->and((string) $deposit->amount)->toBe('100.5000')
            ->and((string) $deposit->net_amount)->toBe('100.5000')
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('100.5000');

        $entry = JournalEntry::query()->findOrFail($deposit->journal_entry_id);
        expect($entry->status->value)->toBe('posted');
        $debit = JournalLine::query()->where('journal_entry_id', $entry->id)->sum('debit_amount');
        $credit = JournalLine::query()->where('journal_entry_id', $entry->id)->sum('credit_amount');
        expect((string) $debit)->toBe((string) $credit)->and((float) $debit)->toBeGreaterThan(0);
    });

    it('is idempotent on the idempotency key: a replay posts no second entry', function () {
        $first = $this->service->deposit($this->accountUuid, 'USD', '50.00', 'dep-dup', 'prov-ref-1');
        $entriesBefore = JournalEntry::query()->count();

        $second = $this->service->deposit($this->accountUuid, 'USD', '50.00', 'dep-dup', 'prov-ref-1');

        expect($second->id)->toBe($first->id)
            ->and(JournalEntry::query()->count())->toBe($entriesBefore)
            ->and(FiatDeposit::query()->where('idempotency_key', 'dep-dup')->count())->toBe(1)
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('50.0000');
    });

    it('charges the configured fee in decimal and credits the net amount', function () {
        config(['wallet.fiat.fee_bps' => 100]); // 1%

        $deposit = $this->service->deposit($this->accountUuid, 'USD', '100.00', 'dep-fee', 'prov-ref-1');

        expect((string) $deposit->fee)->toBe('1.0000')
            ->and((string) $deposit->net_amount)->toBe('99.0000')
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('99.0000');
    });

    it('rejects an unsupported currency and a non-positive amount', function () {
        expect(fn () => $this->service->deposit($this->accountUuid, 'XXX', '10.00', 'dep-x', 'prov'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $this->service->deposit($this->accountUuid, 'USD', '0', 'dep-zero', 'prov'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('fiat withdrawals', function () {
    it('runs the approval workflow and moves funds only on execution', function () {
        $this->service->deposit($this->accountUuid, 'USD', '200.00', 'dep-w', 'prov-ref-1');

        $withdrawal = $this->service->requestWithdrawal($this->accountUuid, 'USD', '80.00', 'wd-1', 'bank-acct-1', 'user-a');
        expect($withdrawal->status)->toBe(FiatWithdrawal::STATUS_PENDING_APPROVAL)
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('200.0000');

        // Four-eyes: the requester cannot approve their own withdrawal.
        expect(fn () => $this->service->approveWithdrawal($withdrawal->uuid, 'user-a'))
            ->toThrow(InvalidArgumentException::class, 'Four-eyes');

        $approved = $this->service->approveWithdrawal($withdrawal->uuid, 'admin-b');
        expect($approved->status)->toBe(FiatWithdrawal::STATUS_APPROVED);

        $executed = $this->service->executeWithdrawal($withdrawal->uuid);
        expect($executed->status)->toBe(FiatWithdrawal::STATUS_COMPLETED)
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('120.0000');

        // Re-execution is a no-op, never a second posting.
        $entriesBefore = JournalEntry::query()->count();
        $again = $this->service->executeWithdrawal($withdrawal->uuid);
        expect($again->status)->toBe(FiatWithdrawal::STATUS_COMPLETED)
            ->and(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('rejects a withdrawal over the per-transaction limit', function () {
        config(['wallet.limits.fiat.withdrawal_per_transaction' => '10']);

        expect(fn () => $this->service->requestWithdrawal($this->accountUuid, 'USD', '100.00', 'wd-limit', null, 'user-a'))
            ->toThrow(LimitExceededException::class);
    });

    it('rejects a withdrawal above the available ledger balance', function () {
        expect(fn () => $this->service->requestWithdrawal($this->accountUuid, 'USD', '10.00', 'wd-nofunds', null, 'user-a'))
            ->toThrow(App\Domain\Wallet\Exceptions\InsufficientBalanceException::class);
    });

    it('cannot execute before approval and can be rejected', function () {
        $this->service->deposit($this->accountUuid, 'USD', '50.00', 'dep-r', 'prov-ref-1');
        $withdrawal = $this->service->requestWithdrawal($this->accountUuid, 'USD', '10.00', 'wd-r', null, 'user-a');

        expect(fn () => $this->service->executeWithdrawal($withdrawal->uuid))
            ->toThrow(InvalidArgumentException::class);

        $rejected = $this->service->rejectWithdrawal($withdrawal->uuid, 'admin-b', 'suspicious');
        expect($rejected->status)->toBe(FiatWithdrawal::STATUS_REJECTED)
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('50.0000');
    });
});

describe('fiat internal transfers', function () {
    it('moves funds between wallets with one balanced ledger entry', function () {
        $this->service->deposit($this->accountUuid, 'USD', '100.00', 'dep-t', 'prov-ref-1');

        $transfer = $this->service->transfer($this->accountUuid, $this->otherAccountUuid, 'USD', '40.00', 'tx-1', 'user-a');

        expect($transfer->status)->toBe(FiatTransfer::STATUS_COMPLETED)
            ->and($this->service->balance($this->accountUuid, 'USD'))->toBe('60.0000')
            ->and($this->service->balance($this->otherAccountUuid, 'USD'))->toBe('40.0000');

        $entry = JournalEntry::query()->findOrFail($transfer->journal_entry_id);
        $debit = (string) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('debit_amount');
        $credit = (string) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('credit_amount');
        expect($debit)->toBe($credit);
    });

    it('rejects a transfer over the daily limit', function () {
        config(['wallet.limits.fiat.transfer_per_day' => '10']);
        $this->service->deposit($this->accountUuid, 'USD', '100.00', 'dep-t2', 'prov-ref-1');

        expect(fn () => $this->service->transfer($this->accountUuid, $this->otherAccountUuid, 'USD', '20.00', 'tx-2'))
            ->toThrow(LimitExceededException::class);
    });
});

describe('fiat reconciliation', function () {
    it('matches when every balance change has a ledger entry', function () {
        $this->service->deposit($this->accountUuid, 'USD', '100.00', 'dep-rec', 'prov-ref-1');
        $w = $this->service->requestWithdrawal($this->accountUuid, 'USD', '25.00', 'wd-rec', null, 'user-a');
        $this->service->approveWithdrawal($w->uuid, 'admin-b');
        $this->service->executeWithdrawal($w->uuid);

        $report = $this->service->reconcile($this->accountUuid, 'USD');

        expect($report['matched'])->toBeTrue()
            ->and($report['status'])->toBe('matched')
            ->and($report['ledger_balance'])->toBe('75.0000')
            ->and($report['expected_balance'])->toBe('75.0000')
            ->and($report['difference'])->toBe('0.0000');
    });

    it('reports requires-manual-review on a mismatch and never auto-corrects', function () {
        $this->service->deposit($this->accountUuid, 'USD', '100.00', 'dep-rec2', 'prov-ref-1');
        // Simulate drift: an intent row deleted outside the service.
        FiatDeposit::query()->where('idempotency_key', 'dep-rec2')->delete();

        $report = $this->service->reconcile($this->accountUuid, 'USD');

        expect($report['matched'])->toBeFalse()
            ->and($report['status'])->toBe('requires-manual-review')
            ->and($report['ledger_balance'])->toBe('100.0000')
            ->and($report['difference'])->toBe('100.0000');
    });
});
