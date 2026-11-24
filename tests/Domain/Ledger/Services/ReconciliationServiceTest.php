<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\ReconciliationStatus;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\ReconciliationService;

beforeEach(function (): void {
    $this->reconciliation = new ReconciliationService(new EloquentDriver());
});

it('classifies matched, ledger-only, provider-only and amount mismatches', function (): void {
    $comparison = $this->reconciliation->compareStatements(
        [
            ['reference' => 'pay-1', 'amount' => '10.25', 'currency' => 'USD'],
            ['reference' => 'pay-2', 'amount' => '5.00', 'currency' => 'USD'],
            ['reference' => 'pay-3', 'amount' => '7.00', 'currency' => 'USD'],
        ],
        [
            ['reference' => 'pay-1', 'amount' => '10.25', 'currency' => 'USD'],
            ['reference' => 'pay-2', 'amount' => '5.50', 'currency' => 'USD'],
            ['reference' => 'pay-4', 'amount' => '1.00', 'currency' => 'USD'],
        ],
    );

    expect($comparison['matched'])->toBe(['pay-1'])
        ->and($comparison['ledger_only'])->toBe(['pay-3'])
        ->and($comparison['provider_only'])->toBe(['pay-4'])
        ->and($comparison['amount_mismatches'])->toHaveCount(1)
        ->and($comparison['amount_mismatches'][0]['reference'])->toBe('pay-2')
        ->and($comparison['amount_mismatches'][0]['ledger_amount'])->toBe('5.00')
        ->and($comparison['amount_mismatches'][0]['provider_amount'])->toBe('5.50');
});

it('records a mismatch report without changing any ledger row', function (): void {
    $entry = JournalEntry::create([
        'entry_number'  => 'JE-TESTRC01',
        'description'   => 'Reconciliation fixture',
        'status'        => 'posted',
        'posted_at'     => now(),
        'source_domain' => 'Payment',
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_code'     => '2001',
        'debit_amount'     => '0.0000',
        'credit_amount'    => '10.2500',
        'currency'         => 'USD',
    ]);

    $before = JournalEntry::count();

    $comparison = $this->reconciliation->compareStatements(
        [['reference' => 'pay-1', 'amount' => '10.25', 'currency' => 'USD']],
        [['reference' => 'pay-1', 'amount' => '9.25', 'currency' => 'USD']],
    );

    $report = $this->reconciliation->recordMismatchReport('payment', now()->subDay(), now(), $comparison);

    expect($report->status)->toBe(ReconciliationStatus::DISCREPANCY)
        ->and(JournalEntry::count())->toBe($before)
        ->and(JournalLine::count())->toBe(1);

    // Resolving the report only marks it reviewed: balances are untouched.
    $resolved = $this->reconciliation->resolveDiscrepancy($report->id, 'Reviewed: provider fee, correcting entry JE-TESTRC02 posted separately.');

    expect($resolved->status)->toBe(ReconciliationStatus::RESOLVED)
        ->and(JournalEntry::count())->toBe($before);
});

it('records a matched report when everything agrees', function (): void {
    $comparison = $this->reconciliation->compareStatements(
        [['reference' => 'pay-1', 'amount' => '10.25', 'currency' => 'USD']],
        [['reference' => 'pay-1', 'amount' => '10.2500', 'currency' => 'USD']],
    );

    $report = $this->reconciliation->recordMismatchReport('payment', now()->subDay(), now(), $comparison);

    expect($report->status)->toBe(ReconciliationStatus::MATCHED);
});
