<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Payment\Events\TransferRequiresManualReview;
use App\Domain\Payment\Events\TransferReversed;
use App\Domain\Payment\Exceptions\ProviderTimeoutException;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\TransferReceipt;
use App\Domain\Payment\Services\PaymentReceiptService;
use App\Domain\Payment\Services\PaymentTransferService;
use App\Domain\Payment\Services\TransferFeeCalculator;
use App\Domain\Payment\Services\TransferValidationService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config([
        'payment_rails.transfers.max_amount_minor'  => 100000000,
        'payment_rails.transfers.daily_limit_minor' => 500000000,
        'payment_rails.transfers.fee_basis_points'  => 0,
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

function transferBalances(LedgerService $ledger): array
{
    return [
        'sender'    => $ledger->getBalance('1001')['amount'],
        'recipient' => $ledger->getBalance('1002')['amount'],
        'hold'      => $ledger->getBalance('HOLD')['amount'],
    ];
}

it('completes an internal transfer and issues a receipt', function (): void {
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-1');

    expect($tx->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and(transferBalances($this->ledger))->toBe([
            'sender'    => '75.0000',
            'recipient' => '25.0000',
            'hold'      => '0.0000',
        ]);

    $receipt = TransferReceipt::query()->where('reference', $tx->reference)->first();

    expect($receipt)->not->toBeNull()
        ->and($receipt->receipt_number)->toStartWith('RCPT-')
        ->and($receipt->amount_minor)->toBe(2500)
        ->and($receipt->amount_decimal)->toBe('25.00')
        ->and($receipt->state)->toBe('successful');
});

it('returns the same transaction on a duplicate idempotency key with a single ledger effect', function (): void {
    $first = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-dup');
    $second = $this->transfers->transfer('1001', '1002', 9900, 'USD', 'idem-dup');

    expect($second->id)->toBe($first->id)
        ->and($second->reference)->toBe($first->reference)
        ->and(PaymentTransaction::count())->toBe(1)
        ->and(transferBalances($this->ledger)['recipient'])->toBe('25.0000');
});

it('rejects an overdraw before creating any transaction', function (): void {
    expect(fn () => $this->transfers->transfer('1001', '1002', 20000, 'USD', 'idem-overdraw'))
        ->toThrow(InvalidArgumentException::class, 'Insufficient funds');

    expect(PaymentTransaction::count())->toBe(0)
        ->and(transferBalances($this->ledger)['sender'])->toBe('100.0000');
});

it('rejects unknown parties, same-account and over-limit transfers', function (): void {
    expect(fn () => $this->transfers->transfer('1001', 'NOPE', 100, 'USD', 'idem-v1'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
    expect(fn () => $this->transfers->transfer('1001', '1001', 100, 'USD', 'idem-v2'))
        ->toThrow(InvalidArgumentException::class, 'must differ');

    config(['payment_rails.transfers.max_amount_minor' => 1000]);
    expect(fn () => $this->transfers->transfer('1001', '1002', 1001, 'USD', 'idem-v3'))
        ->toThrow(InvalidArgumentException::class, 'exceeds the per-transfer limit');
});

it('marks a timed-out provider call unknown and keeps the hold', function (): void {
    Event::fake([TransferRequiresManualReview::class]);

    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-timeout', function () {
        throw new ProviderTimeoutException('upstream timed out after 30s');
    });

    expect($tx->status)->toBe(TransactionState::UNKNOWN->value)
        ->and($tx->unknown_since_at)->not->toBeNull()
        ->and(transferBalances($this->ledger))->toBe([
            'sender'    => '75.0000',
            'recipient' => '0.0000',
            'hold'      => '25.0000',
        ]);

    Event::assertDispatched(TransferRequiresManualReview::class);
});

it('resolves an unknown transfer to successful by settling the hold', function (): void {
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-resolve-ok', function () {
        throw new ProviderTimeoutException('timeout');
    });

    $resolved = $this->transfers->resolveUnknown($tx, TransactionState::SUCCESSFUL, 'Status query confirmed success');

    expect($resolved->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and(transferBalances($this->ledger))->toBe([
            'sender'    => '75.0000',
            'recipient' => '25.0000',
            'hold'      => '0.0000',
        ])
        ->and(TransferReceipt::query()->where('reference', $tx->reference)->exists())->toBeTrue();
});

it('reverses a failed transfer with a mirror entry and returns the funds', function (): void {
    Event::fake([TransferReversed::class]);

    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-fail', fn () => ['status' => 'failed']);

    expect($tx->status)->toBe(TransactionState::FAILED->value)
        ->and($tx->reversed_by)->not->toBeNull()
        ->and(transferBalances($this->ledger))->toBe([
            'sender'    => '100.0000',
            'recipient' => '0.0000',
            'hold'      => '0.0000',
        ]);

    Event::assertDispatched(TransferReversed::class);

    // The reservation entry itself is REVERSED, not edited or deleted.
    $reservationId = $tx->metadata['reservation_entry_id'];
    expect(App\Domain\Ledger\Models\JournalEntry::find($reservationId)->status->value)->toBe('reversed');
});

it('resolves an unknown transfer to failed and returns the funds', function (): void {
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-resolve-fail', function () {
        throw new ProviderTimeoutException('timeout');
    });

    $resolved = $this->transfers->resolveUnknown($tx, TransactionState::FAILED, 'Provider confirmed the transfer failed');

    expect($resolved->status)->toBe(TransactionState::FAILED->value)
        ->and(transferBalances($this->ledger)['sender'])->toBe('100.0000')
        ->and(transferBalances($this->ledger)['hold'])->toBe('0.0000');
});

it('settles a provider-confirmed transfer through the hold account', function (): void {
    $tx = $this->transfers->transfer('1001', '1002', 2500, 'USD', 'idem-provider-ok', fn () => [
        'status'             => 'successful',
        'provider_reference' => 'prov-123',
    ]);

    expect($tx->status)->toBe(TransactionState::SUCCESSFUL->value)
        ->and($tx->external_reference)->toBe('prov-123')
        ->and(transferBalances($this->ledger))->toBe([
            'sender'    => '75.0000',
            'recipient' => '25.0000',
            'hold'      => '0.0000',
        ]);
});
