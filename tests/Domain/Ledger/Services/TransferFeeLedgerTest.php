<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Services\PaymentReceiptService;
use App\Domain\Payment\Services\PaymentTransferService;
use App\Domain\Payment\Services\TransferFeeCalculator;
use App\Domain\Payment\Services\TransferValidationService;

beforeEach(function (): void {
    config([
        'payment_rails.transfers.max_amount_minor'  => 100000000,
        'payment_rails.transfers.daily_limit_minor' => 500000000,
        'payment_rails.transfers.fee_basis_points'  => 100, // 1%
        'payment_rails.transfers.fee_fixed_minor'   => 0,
        'payment_rails.transfers.hold_account_code' => 'HOLD',
    ]);

    LedgerAccount::create(['code' => '1001', 'name' => 'Sender asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '1002', 'name' => 'Recipient asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '2001', 'name' => 'Funding liability', 'type' => 'liability', 'currency' => 'USD']);
    LedgerAccount::create(['code' => 'HOLD', 'name' => 'Transfer hold', 'type' => 'asset', 'currency' => 'USD']);

    $this->ledger = new LedgerService(new EloquentDriver());
    // Fund the sender with 100.00 USD.
    $this->ledger->post('Funding', [
        ['account_code' => '1001', 'debit' => '100.0000', 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '100.0000'],
    ]);

    $this->transfers = new PaymentTransferService(
        $this->ledger,
        new TransferValidationService(new TransferFeeCalculator()),
        new PaymentReceiptService(),
    );
});

it('posts the fee as a separate entry carved out of the amount', function (): void {
    // 2500 minor = 25.00 gross; 1% fee = 0.25; recipient gets 24.75 net.
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'fee-idem-1');

    expect($tx->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and($this->ledger->getBalance('1001')['amount'])->toBe('75.0000')
        ->and($this->ledger->getBalance('1002')['amount'])->toBe('24.7500')
        ->and($this->ledger->getBalance('PAYMENT:FEES:USD')['amount'])->toBe('0.2500');

    // Funding + principal + fee = 3 entries; the fee is its own entry.
    expect(JournalEntry::count())->toBe(3);

    $feeEntry = JournalEntry::where('description', 'like', 'Transfer fee %')->first();
    expect($feeEntry)->not->toBeNull()
        ->and($feeEntry->isBalanced())->toBeTrue()
        ->and($feeEntry->source_event_id)->toBe($tx->reference)
        ->and($tx->metadata['fee_entry_status'])->toBe('posted')
        ->and($tx->metadata['fee_entry_id'])->toBe($feeEntry->id);

    // Money is conserved exactly: sender + recipient + fees == 100.00.
    $total = bcadd(
        bcadd($this->ledger->getBalance('1001')['amount'], $this->ledger->getBalance('1002')['amount'], 4),
        $this->ledger->getBalance('PAYMENT:FEES:USD')['amount'],
        4,
    );
    expect($total)->toBe('100.0000');
});

it('posts the fee from the hold when a provider transfer settles', function (): void {
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'fee-idem-2', fn () => [
        'status'             => 'successful',
        'provider_reference' => 'prov-42',
    ]);

    expect($tx->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and($this->ledger->getBalance('1001')['amount'])->toBe('75.0000')
        ->and($this->ledger->getBalance('1002')['amount'])->toBe('24.7500')
        ->and($this->ledger->getBalance('HOLD')['amount'])->toBe('0.0000')
        ->and($this->ledger->getBalance('PAYMENT:FEES:USD')['amount'])->toBe('0.2500')
        ->and($tx->metadata['fee_entry_status'])->toBe('posted');
});

it('posts no fee entry when the fee is zero', function (): void {
    config(['payment_rails.transfers.fee_basis_points' => 0]);

    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'fee-idem-3');

    expect($tx->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and($this->ledger->getBalance('1002')['amount'])->toBe('25.0000')
        ->and(JournalEntry::where('description', 'like', 'Transfer fee %')->count())->toBe(0)
        ->and(LedgerAccount::where('code', 'PAYMENT:FEES:USD')->exists())->toBeFalse();
});

it('does not duplicate the fee entry when the transfer is replayed', function (): void {
    $first = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'fee-idem-dup');
    $second = $this->transfers->transfer('1001', '1002', 9900, 'USD', 'fee-idem-dup');

    expect($second->id)->toBe($first->id)
        ->and(PaymentTransaction::count())->toBe(1)
        ->and(JournalEntry::where('description', 'like', 'Transfer fee %')->count())->toBe(1)
        ->and($this->ledger->getBalance('PAYMENT:FEES:USD')['amount'])->toBe('0.2500');
});
