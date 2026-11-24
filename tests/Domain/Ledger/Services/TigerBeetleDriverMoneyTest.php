<?php

declare(strict_types=1);

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Services\Drivers\TigerBeetleDriver;
use Illuminate\Support\Facades\Http;

it('converts decimal amounts to integer minor units exactly', function (): void {
    Http::fake();

    $entry = JournalEntry::create([
        'entry_number'  => 'JE-TESTTB01',
        'description'   => 'TigerBeetle exact conversion',
        'status'        => 'posted',
        'posted_at'     => now(),
        'source_domain' => 'Payment',
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_code'     => '1001',
        'debit_amount'     => '10.0005',
        'credit_amount'    => '0.0000',
        'currency'         => 'USD',
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_code'     => '2001',
        'debit_amount'     => '0.0000',
        'credit_amount'    => '10.0005',
        'currency'         => 'USD',
    ]);
    $entry->load('lines');

    (new TigerBeetleDriver())->post($entry);

    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url() === 'http://127.0.0.1:3001/create_transfers'
            && $payload[0]['amount'] === 100005;
    });
});

it('returns an exact signed decimal balance from integer minor units', function (): void {
    Http::fake([
        'http://127.0.0.1:3001/lookup_accounts' => Http::response([
            ['debits_posted' => 100005, 'credits_posted' => 5],
        ], 200),
    ]);

    $balance = (new TigerBeetleDriver())->balance('1001');

    // (100005 - 5) / 10000 = 10.0000 exactly — no float division.
    expect($balance['amount'])->toBe('10.0000');
});
