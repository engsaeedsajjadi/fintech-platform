<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\Money as AccountMoney;
use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Exceptions\LedgerPrecisionException;
use App\Domain\Ledger\Exceptions\LedgerUnavailableException;
use App\Domain\Ledger\Listeners\PostGlEntryListener;
use App\Domain\Ledger\Models\IdempotencyKey;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerDriverSync;
use App\Domain\Ledger\Models\LedgerPostingFailure;
use App\Domain\Ledger\Models\PostingRule;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\Drivers\TigerBeetleDriver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\PostingRuleEngine;
use App\Domain\Ledger\Services\ReconciliationService;
use App\Domain\Ledger\ValueObjects\LedgerPrecision;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

final class Phase42DepositEvent
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $id,
    ) {
    }
}

final class Phase42MoneyAddedEvent
{
    public function __construct(
        public readonly AccountMoney $money,
        public readonly string $id,
    ) {
    }
}

final class Phase42FlakyDriver implements LedgerDriverInterface
{
    public bool $failPost = false;

    public int $postAttempts = 0;

    private EloquentDriver $eloquent;

    public function __construct()
    {
        $this->eloquent = new EloquentDriver();
    }

    public function post(JournalEntry $entry): void
    {
        $this->postAttempts++;

        if ($this->failPost) {
            throw new RuntimeException('TigerBeetle down (test double)');
        }
    }

    public function balance(string $accountCode, ?Carbon $asOf = null): array
    {
        return $this->eloquent->balance($accountCode, $asOf);
    }

    public function trialBalance(?Carbon $asOf = null): array
    {
        return $this->eloquent->trialBalance($asOf);
    }

    public function accountHistory(string $accountCode, Carbon $from, Carbon $to): Collection
    {
        return $this->eloquent->accountHistory($accountCode, $from, $to);
    }
}

beforeEach(function (): void {
    $this->ledger = new LedgerService(new EloquentDriver());

    LedgerAccount::create(['code' => '1001', 'name' => 'Sender asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '1002', 'name' => 'Recipient asset', 'type' => 'asset', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '2001', 'name' => 'Funding liability', 'type' => 'liability', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '3001', 'name' => 'Euro asset', 'type' => 'asset', 'currency' => 'EUR']);
    LedgerAccount::create(['code' => '3002', 'name' => 'Euro liability', 'type' => 'liability', 'currency' => 'EUR']);
    LedgerAccount::create(['code' => '4001', 'name' => 'Fee income', 'type' => 'revenue', 'currency' => 'USD']);
    LedgerAccount::create(['code' => '9001', 'name' => 'Inactive asset', 'type' => 'asset', 'currency' => 'USD', 'is_active' => false]);
    LedgerAccount::create(['code' => 'BTC1', 'name' => 'BTC asset', 'type' => 'asset', 'currency' => 'BTC']);
    LedgerAccount::create(['code' => 'BTC2', 'name' => 'BTC liability', 'type' => 'liability', 'currency' => 'BTC']);
});

function phase42Lines(string $debit = '10.0000', string $credit = '10.0000'): array
{
    return [
        ['account_code' => '1001', 'debit' => $debit, 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => $credit],
    ];
}

// ---------------------------------------------------------------- (A) events
it('registers the GL listener as a Spatie Reactor handling every stored event', function (): void {
    $listener = new PostGlEntryListener($this->ledger, new PostingRuleEngine());

    expect($listener)->toBeInstanceOf(Reactor::class);

    $methods = $listener->getEventHandlingMethods()->toArray();

    expect($methods)->toHaveKey('object')
        ->and($methods['object'])->toContain('onEvent');
});

it('posts a journal entry when a posting rule matches the event', function (): void {
    PostingRule::create([
        'name'              => 'Deposit posts cash to funding',
        'trigger_event'     => 'Phase42DepositEvent',
        'debit_account'     => '1001',
        'credit_account'    => '2001',
        'amount_expression' => 'event.amount',
    ]);

    $listener = new PostGlEntryListener($this->ledger, new PostingRuleEngine());
    $listener->onEvent(new Phase42DepositEvent('25.0000', 'USD', 'evt-42-a'));

    expect(JournalEntry::count())->toBe(1)
        ->and($this->ledger->getBalance('1001')['amount'])->toBe('25.0000');

    // A replayed event maps to the same idempotency key: no second entry.
    $listener->onEvent(new Phase42DepositEvent('25.0000', 'USD', 'evt-42-a'));

    expect(JournalEntry::count())->toBe(1);
});

it('converts minor-unit Money objects instead of mis-posting cents as whole units', function (): void {
    PostingRule::create([
        'name'              => 'MoneyAdded posts minor units',
        'trigger_event'     => 'Phase42MoneyAddedEvent',
        'debit_account'     => '1001',
        'credit_account'    => '2001',
        'amount_expression' => 'event.amount_minor',
    ]);

    $listener = new PostGlEntryListener($this->ledger, new PostingRuleEngine());
    $listener->onEvent(new Phase42MoneyAddedEvent(new AccountMoney(2500), 'evt-42-b'));

    // 2500 minor units USD = 25.00 major — NOT 2500.00.
    expect(JournalEntry::count())->toBe(1)
        ->and($this->ledger->getBalance('1001')['amount'])->toBe('25.0000');
});

it('does nothing when no posting rule matches the event', function (): void {
    $listener = new PostGlEntryListener($this->ledger, new PostingRuleEngine());
    $listener->onEvent(new Phase42DepositEvent('25.0000', 'USD', 'evt-42-c'));

    expect(JournalEntry::count())->toBe(0)
        ->and(LedgerPostingFailure::count())->toBe(0);
});

// ------------------------------------------------------------- (D) failures
it('records a failed auto-posting durably and rethrows instead of swallowing it', function (): void {
    PostingRule::create([
        'name'              => 'Rule pointing at an inactive account',
        'trigger_event'     => 'Phase42DepositEvent',
        'debit_account'     => '9001',
        'credit_account'    => '2001',
        'amount_expression' => 'event.amount',
    ]);

    $listener = new PostGlEntryListener($this->ledger, new PostingRuleEngine());

    expect(fn () => $listener->onEvent(new Phase42DepositEvent('25.0000', 'USD', 'evt-42-d')))
        ->toThrow(RuntimeException::class, 'inactive');

    expect(JournalEntry::count())->toBe(0);

    $failure = LedgerPostingFailure::query()->first();
    expect($failure)->not->toBeNull()
        ->and($failure->event_name)->toBe('Phase42DepositEvent')
        ->and($failure->status)->toBe('pending')
        ->and($failure->attempts)->toBe(1)
        ->and($failure->idempotency_key)->toBe('gl:Phase42DepositEvent:evt-42-d');
});

// ------------------------------------------------------- (B) double-entry
it('rejects an entry mixing currencies', function (): void {
    expect(fn () => $this->ledger->post('Mixed', [
        ['account_code' => '1001', 'debit' => '10.0000', 'credit' => '0.0000', 'currency' => 'USD'],
        ['account_code' => '3002', 'debit' => '0.0000', 'credit' => '10.0000', 'currency' => 'EUR'],
    ]))->toThrow(RuntimeException::class, 'mixes currencies');

    expect(JournalEntry::count())->toBe(0);
});

it('inherits the currency from the account instead of mislabelling EUR as USD', function (): void {
    $entry = $this->ledger->post('Euro transfer', [
        ['account_code' => '3001', 'debit' => '10.0000', 'credit' => '0.0000'],
        ['account_code' => '3002', 'debit' => '0.0000', 'credit' => '10.0000'],
    ]);

    expect($entry->lines()->pluck('currency')->unique()->all())->toBe(['EUR'])
        ->and($this->ledger->getBalance('3001'))->toBe(['amount' => '10.0000', 'currency' => 'EUR']);
});

it('rejects a line whose currency contradicts its account', function (): void {
    expect(fn () => $this->ledger->post('Mismatch', [
        ['account_code' => '1001', 'debit' => '10.0000', 'credit' => '0.0000', 'currency' => 'EUR'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '10.0000', 'currency' => 'EUR'],
    ]))->toThrow(RuntimeException::class, 'does not match');
});

it('rejects postings to an inactive account', function (): void {
    expect(fn () => $this->ledger->post('Inactive', [
        ['account_code' => '9001', 'debit' => '10.0000', 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '10.0000'],
    ]))->toThrow(RuntimeException::class, 'inactive');
});

it('rejects unregistered accounts when the enforcement flag is on', function (): void {
    config(['ledger.require_registered_accounts' => true]);

    expect(fn () => $this->ledger->post('Ghost', [
        ['account_code' => 'NOPE', 'debit' => '10.0000', 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '10.0000'],
    ]))->toThrow(RuntimeException::class, 'not registered');
});

// --------------------------------------------------------- (C) idempotency
it('returns the original entry for a same-payload replay', function (): void {
    $first = $this->ledger->postIdempotent('key-42', 'First', phase42Lines(), 'Payment', 'evt-1');
    $second = $this->ledger->postIdempotent('key-42', 'First', phase42Lines(), 'Payment', 'evt-1');

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1);
});

it('refuses a replay with a different payload under the same key', function (): void {
    $this->ledger->postIdempotent('key-42-conflict', 'First', phase42Lines('10.0000', '10.0000'), 'Payment', 'evt-1');

    expect(fn () => $this->ledger->postIdempotent('key-42-conflict', 'First', phase42Lines('99.0000', '99.0000'), 'Payment', 'evt-1'))
        ->toThrow(RuntimeException::class, 'different payload');

    expect(JournalEntry::count())->toBe(1);
});

it('reclaims a stale claim left by a crash instead of blocking the key forever', function (): void {
    IdempotencyKey::create([
        'key'              => 'key-42-stale',
        'domain'           => 'Payment',
        'journal_entry_id' => null,
        'request_hash'     => null,
        'result'           => null,
    ]);
    DB::table('ledger_idempotency_keys')
        ->where('key', 'key-42-stale')
        ->update(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    $entry = $this->ledger->postIdempotent('key-42-stale', 'After crash', phase42Lines(), 'Payment', 'evt-1');

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and(JournalEntry::count())->toBe(1)
        ->and(IdempotencyKey::where('key', 'key-42-stale')->first()->journal_entry_id)->toBe($entry->id);
});

it('releases the claim when posting fails so the key can be retried', function (): void {
    // A direct post already owns this idempotency key on journal_entries,
    // so the idempotent post fails INSIDE persistEntry (unique violation)
    // — after the claim was taken. The claim must be released, not left
    // behind to poison the key.
    $this->ledger->post('Direct', phase42Lines(), null, null, 'key-42-poison');

    expect(fn () => $this->ledger->postIdempotent('key-42-poison', 'Retry', phase42Lines()))
        ->toThrow(QueryException::class);

    expect(IdempotencyKey::where('key', 'key-42-poison')->exists())->toBeFalse();

    // And the key space itself is healthy: a fresh key posts fine.
    $entry = $this->ledger->postIdempotent('key-42-poison-2', 'Good', phase42Lines(), 'Payment', 'evt-1');

    expect($entry)->toBeInstanceOf(JournalEntry::class);
});

// ----------------------------------------------------------- (F) precision
it('refuses a crypto amount the 4-decimal ledger cannot represent', function (): void {
    expect(fn () => $this->ledger->post('One sat', [
        ['account_code' => 'BTC1', 'debit' => '0.00000001', 'credit' => '0.0000', 'currency' => 'BTC'],
        ['account_code' => 'BTC2', 'debit' => '0.0000', 'credit' => '0.00000001', 'currency' => 'BTC'],
    ]))->toThrow(LedgerPrecisionException::class, 'needs 8 decimals');

    expect(JournalEntry::count())->toBe(0);
});

it('keeps the pinned fiat dust behaviour: sub-0.0001 USD truncates, it does not fail', function (): void {
    $entry = $this->ledger->post('Dust', [
        ['account_code' => '1001', 'debit' => '10', 'credit' => '0'],
        ['account_code' => '2001', 'debit' => '0.00005', 'credit' => '10'],
    ]);

    expect($entry->isBalanced())->toBeTrue()
        ->and(LedgerPrecision::nativeScale('USD'))->toBe(2)
        ->and(LedgerPrecision::nativeScale('BTC'))->toBe(8)
        ->and(LedgerPrecision::nativeScale('USDT'))->toBe(6)
        ->and(LedgerPrecision::exceedsPostingScale('BTC'))->toBeTrue()
        ->and(LedgerPrecision::exceedsPostingScale('USD'))->toBeFalse();
});

// ------------------------------------------------------ (E) driver sync
it('records a failed driver sync in the outbox instead of failing the posting', function (): void {
    $driver = new Phase42FlakyDriver();
    $driver->failPost = true;
    $ledger = new LedgerService($driver);

    $entry = $ledger->post('Sync will fail', phase42Lines());

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and(JournalEntry::count())->toBe(1);

    $row = LedgerDriverSync::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->attempts)->toBe(1)
        ->and($row->journal_entry_id)->toBe($entry->id);
});

it('retries pending syncs and marks them synced only after the driver confirms', function (): void {
    $driver = new Phase42FlakyDriver();
    $driver->failPost = true;
    $ledger = new LedgerService($driver);
    $ledger->post('Sync will fail', phase42Lines());

    $service = new ReconciliationService($driver);

    // Still failing: the row stays pending.
    $result = $service->retryPendingDriverSyncs();
    expect($result)->toBe(['retried' => 1, 'synced' => 0, 'failed' => 1])
        ->and(LedgerDriverSync::query()->first()->status)->toBe('pending');

    // Recovered: the retry syncs and clears the row.
    $driver->failPost = false;
    $result = $service->retryPendingDriverSyncs();
    expect($result)->toBe(['retried' => 1, 'synced' => 1, 'failed' => 0])
        ->and(LedgerDriverSync::query()->first()->status)->toBe('synced');
});

it('enforces the overdraw guard from the DB journal even when the driver is down', function (): void {
    $driver = new Phase42FlakyDriver();
    $driver->failPost = true;
    $ledger = new LedgerService($driver);

    $ledger->post('Funding', phase42Lines('100.0000', '100.0000'));

    expect(fn () => $ledger->postGuarded('Overdraw', [
        ['account_code' => '1001', 'debit' => '0.0000', 'credit' => '200.0000'],
        ['account_code' => '1002', 'debit' => '200.0000', 'credit' => '0.0000'],
    ], ['1001']))->toThrow(RuntimeException::class, 'overdraw');

    // The guard never consulted the (down) driver, and the failed funding
    // sync is still pending in the outbox rather than lost.
    expect(LedgerDriverSync::where('status', 'pending')->count())->toBe(1);
});

it('reports a real zero for an unknown account with no lines (the DB read succeeded)', function (): void {
    expect($this->ledger->getBalance('NOPE'))->toBe(['amount' => '0.0000', 'currency' => 'USD']);
});

it('sums the lines of a chart-less trading-style account instead of reporting zero', function (): void {
    // Trading virtual accounts have journal lines but no chart row; they
    // are read with the historical credit-normal convention.
    $this->ledger->post('Trading leg', [
        ['account_code' => 'BTC1', 'debit' => '1.5000', 'credit' => '0.0000', 'currency' => 'BTC'],
        ['account_code' => 'trading:account:u1:BTC', 'debit' => '0.0000', 'credit' => '1.5000', 'currency' => 'BTC'],
    ]);

    expect($this->ledger->getBalance('trading:account:u1:BTC'))
        ->toBe(['amount' => '1.5000', 'currency' => 'BTC']);
});

// ------------------------------------------------- (E) TigerBeetle driver
it('throws instead of returning a fabricated zero balance when TigerBeetle is unreachable', function (): void {
    config(['ledger.tigerbeetle.addresses' => '127.0.0.1:19999']);
    $driver = new TigerBeetleDriver();

    expect(fn () => $driver->balance('1100'))->toThrow(LedgerUnavailableException::class);
});

it('throws instead of returning an empty history when TigerBeetle is unreachable', function (): void {
    config(['ledger.tigerbeetle.addresses' => '127.0.0.1:19999']);
    $driver = new TigerBeetleDriver();

    expect(fn () => $driver->accountHistory('1100', now()->subDays(7), now()))
        ->toThrow(LedgerUnavailableException::class);
});

it('decomposes a multi-leg entry into amount-paired transfers', function (): void {
    Http::fake();

    $entry = $this->ledger->post('Three legs', [
        ['account_code' => '1001', 'debit' => '10.0000', 'credit' => '0.0000'],
        ['account_code' => '1002', 'debit' => '5.0000', 'credit' => '0.0000'],
        ['account_code' => '2001', 'debit' => '0.0000', 'credit' => '15.0000'],
    ]);

    (new TigerBeetleDriver())->post($entry);

    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url() === 'http://127.0.0.1:3001/create_transfers'
            && count($payload) === 2
            && $payload[0]['amount'] === 100000
            && $payload[1]['amount'] === 50000
            && $payload[0]['credit_account_id'] === $payload[1]['credit_account_id'];
    });
});

it('refuses a TigerBeetle sync whose per-transfer results report a failure', function (): void {
    Http::fake([
        'http://127.0.0.1:3001/create_transfers' => Http::response([
            ['index' => 0, 'result' => 'exceeds_credits'],
        ], 200),
    ]);

    $entry = $this->ledger->post('Will be rejected', phase42Lines());

    expect(fn () => (new TigerBeetleDriver())->post($entry))
        ->toThrow(RuntimeException::class, 'rejected');
});

it('retries pending driver syncs through the artisan command', function (): void {
    Http::fake();

    $driver = new Phase42FlakyDriver();
    $driver->failPost = true;
    (new LedgerService($driver))->post('Sync will fail', phase42Lines());

    expect(LedgerDriverSync::where('status', 'pending')->count())->toBe(1);

    config(['ledger.driver' => 'tigerbeetle']);

    $this->artisan('ledger:retry-driver-sync')->assertExitCode(0);

    expect(LedgerDriverSync::where('status', 'synced')->count())->toBe(1);
});
