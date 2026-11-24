<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\LedgerService;

beforeEach(function (): void {
    $this->ledger = new LedgerService(new EloquentDriver());

    LedgerAccount::create(['code' => '1001', 'name' => 'Sender asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '1002', 'name' => 'Recipient asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '2001', 'name' => 'Funding liability', 'type' => 'liability', 'currency' => 'USD']);
});

function hardeningLines(string $debit, string $credit): array
{
    return [
        ['account_code' => '1001', 'debit' => $debit, 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => $credit],
    ];
}

it('returns the original entry when the same idempotency key is replayed', function (): void {
    $first = $this->ledger->postIdempotent('key-1', 'First', hardeningLines('10.0000', '10.0000'), 'Payment', 'evt-1');
    $second = $this->ledger->postIdempotent('key-1', 'Replay with different description', hardeningLines('99.0000', '99.0000'), 'Payment', 'evt-2');

    expect($second->id)->toBe($first->id)
        ->and($second->entry_number)->toBe($first->entry_number)
        ->and(JournalEntry::count())->toBe(1);
});

it('rejects unbalanced, zero and negative entries', function (): void {
    expect(fn () => $this->ledger->post('Unbalanced', hardeningLines('10.0000', '9.0000')))
        ->toThrow(RuntimeException::class, 'unbalanced');

    expect(fn () => $this->ledger->post('Zero', hardeningLines('0.0000', '0.0000')))
        ->toThrow(RuntimeException::class, 'zero total');

    expect(fn () => $this->ledger->post('Negative', hardeningLines('-5.0000', '-5.0000')))
        ->toThrow(RuntimeException::class, 'must not be negative');

    expect(JournalEntry::count())->toBe(0);
});

it('normalises exponent-form amounts exactly instead of failing', function (): void {
    $entry = $this->ledger->post('Exponent', [
        ['account_code' => '1001', 'debit' => '1e3', 'credit' => '0'],
        ['account_code' => '2001', 'debit' => '0', 'credit' => '1e3'],
    ]);

    expect($entry->isBalanced())->toBeTrue()
        // SQLite hands decimal columns back as numbers, MySQL as strings.
        ->and(App\Support\DecimalString::fromNumeric($entry->lines()->where('account_code', '2001')->first()->credit_amount, 4))->toBe('1000.0000');
});

it('makes posted entries immutable and reverses only via a mirror entry', function (): void {
    $entry = $this->ledger->post('Original', hardeningLines('25.0000', '25.0000'));

    expect(fn () => $entry->update(['description' => 'Edited']))
        ->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'cannot be deleted');
    expect(fn () => $entry->lines()->first()->update(['narrative' => 'Edited']))
        ->toThrow(RuntimeException::class, 'immutable');

    $reversal = $this->ledger->reverse($entry->id, 'test reversal');

    expect($reversal->id)->not->toBe($entry->id)
        ->and($entry->refresh()->status)->toBe(EntryStatus::REVERSED)
        ->and($entry->refresh()->reversed_by)->toBe($reversal->id)
        ->and($reversal->isBalanced())->toBeTrue();

    // A second reversal of the same entry must fail — no orphan mirrors.
    expect(fn () => $this->ledger->reverse($entry->id, 'again'))
        ->toThrow(RuntimeException::class, 'Cannot reverse');
    expect(JournalEntry::count())->toBe(2);
});

it('refuses a guarded posting that would overdraw and leaves the balance unchanged', function (): void {
    // Fund the sender with 100.00.
    $this->ledger->post('Funding', hardeningLines('100.0000', '100.0000'));

    $this->ledger->postGuarded('Spend 60', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '60.0000'],
        ['account_code' => '1002', 'debit' => '60.0000', 'credit' => '0.0000'],
    ], ['1001']);

    expect($this->ledger->getBalance('1001')['amount'])->toBe('40.0000');

    // A second 60.00 debit would take the sender to -20.00: rejected.
    expect(fn () => $this->ledger->postGuarded('Spend 60 again', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '60.0000'],
        ['account_code' => '1002', 'debit' => '60.0000', 'credit' => '0.0000'],
    ], ['1001']))->toThrow(RuntimeException::class, 'overdraw');

    expect($this->ledger->getBalance('1001')['amount'])->toBe('40.0000')
        ->and($this->ledger->getBalance('1002')['amount'])->toBe('60.0000');
});

it('bumps the aggregate version on every guarded posting', function (): void {
    $this->ledger->post('Funding', hardeningLines('100.0000', '100.0000'));

    expect(LedgerAccount::where('code', '1001')->first()->version)->toBe(0);

    $this->ledger->postGuarded('Spend 10', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '10.0000'],
        ['account_code' => '1002', 'debit' => '10.0000', 'credit' => '0.0000'],
    ], ['1001']);

    expect(LedgerAccount::where('code', '1001')->first()->version)->toBe(1);

    $this->ledger->postGuarded('Spend 10 more', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '10.0000'],
        ['account_code' => '1002', 'debit' => '10.0000', 'credit' => '0.0000'],
    ], ['1001']);

    expect(LedgerAccount::where('code', '1001')->first()->version)->toBe(2);
});

it('restores balances exactly once when a posted entry is reversed', function (): void {
    $this->ledger->post('Funding', hardeningLines('100.0000', '100.0000'));

    $spend = $this->ledger->postGuarded('Spend 60', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '60.0000'],
        ['account_code' => '1002', 'debit' => '60.0000', 'credit' => '0.0000'],
    ], ['1001']);

    expect($this->ledger->getBalance('1001')['amount'])->toBe('40.0000');

    $this->ledger->reverse($spend->id, 'reversal regression test');

    // The original entry is REVERSED and its mirror nets it out — the
    // reversal must count exactly once, not twice.
    expect($this->ledger->getBalance('1001')['amount'])->toBe('100.0000')
        ->and($this->ledger->getBalance('1002')['amount'])->toBe('0.0000');
});

it('fails closed when a guarded account does not exist', function (): void {
    expect(fn () => $this->ledger->postGuarded('Missing account', hardeningLines('10.0000', '10.0000'), ['NOPE']))
        ->toThrow(RuntimeException::class, 'does not exist');
});
