<?php

declare(strict_types=1);

use App\Domain\Exchange\Models\OtcDeal;
use App\Domain\Exchange\Models\TradingHold;
use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function TradingRoomTest\enablePair;
use function TradingRoomTest\enableTrading;
use function TradingRoomTest\ensureAssets;
use function TradingRoomTest\makeAccount;
use function TradingRoomTest\otcService;

require_once __DIR__ . '/../trading-room-helpers.php';

beforeEach(function (): void {
    enableTrading();
    ensureAssets();
    enablePair(marketOrders: true);
});

it('settles an OTC deal even when the ledger driver sync fails, and records the failed sync in the outbox', function (): void {
    // A driver whose sync always fails (e.g. TigerBeetle down). Ledger
    // records the failure in its driver-sync outbox and does NOT fail
    // the posting: the DB journal is authoritative.
    app()->bind(LedgerDriverInterface::class, fn () => new class () implements LedgerDriverInterface {
        public function post(JournalEntry $entry): void
        {
            throw new RuntimeException('TigerBeetle down (test fake)');
        }

        /** @return array{amount: string, currency: string} */
        public function balance(string $accountCode, ?Carbon $asOf = null): array
        {
            return ['amount' => '0', 'currency' => 'USD'];
        }

        /** @return array<string, array{debit: string, credit: string, balance: string}> */
        public function trialBalance(?Carbon $asOf = null): array
        {
            return [];
        }

        /** @return Collection<int, array<string, mixed>> */
        public function accountHistory(string $accountCode, Carbon $from, Carbon $to): Collection
        {
            return collect();
        }
    });

    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');
    $deal = otcService()->acceptQuote($quote->quote_id);

    expect($deal->status)->toBe('settled')
        ->and($deal->ledger_entry_numbers)->toHaveCount(2);
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(2);
    expect(TradingHold::active()->count())->toBe(0);

    // Both legs are durably recorded as pending sync, for retry by the
    // ledger's driver-sync reconciler — the deal is settled, the sync
    // is not lost.
    expect(DB::table('ledger_driver_syncs')->where('status', 'pending')->count())->toBe(2);
});

it('resumes a pending OTC deal without double-posting its legs', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');
    $deal = otcService()->acceptQuote($quote->quote_id);

    expect($deal->status)->toBe('settled');
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(2);

    // Simulate a crash after the legs posted but before finalise ran:
    // the journal entries exist, the deal/quote/hold look un-finalised.
    $hold = TradingHold::where('source_type', 'otc')->where('source_id', $quote->quote_id)->firstOrFail();
    $hold->update(['status' => 'active', 'amount' => (string) $quote->total, 'consumed_at' => null]);
    $deal->update(['status' => 'pending_settlement', 'ledger_entry_numbers' => null, 'settled_at' => null]);
    $quote->update(['status' => 'quoted', 'settled_at' => null]);

    $again = otcService()->acceptQuote($quote->quote_id);

    expect($again->deal_id)->toBe($deal->deal_id);
    expect($again->status)->toBe('settled');
    expect(OtcDeal::count())->toBe(1);
    // The two posted legs were replayed, not posted again.
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(2);
    expect($again->ledger_entry_numbers)->toHaveCount(2);
    expect(TradingHold::active()->count())->toBe(0);
    expect($quote->refresh()->status)->toBe('settled');
});

it('rejects OTC amounts beyond the asset precision', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    // BTC has precision 8: 9dp is refused, never rounded.
    expect(fn () => otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.000000001'))
        ->toThrow(InvalidArgumentException::class, 'decimal places');

    expect(App\Domain\Exchange\Models\OtcQuote::count())->toBe(0);
});
