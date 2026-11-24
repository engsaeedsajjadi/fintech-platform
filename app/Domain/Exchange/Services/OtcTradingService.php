<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Services\TradingPairService;
use App\Domain\Exchange\Events\Otc\OtcDealSettled;
use App\Domain\Exchange\Events\Otc\OtcQuoteAccepted;
use App\Domain\Exchange\Events\Otc\OtcQuoteExpired;
use App\Domain\Exchange\Events\Otc\OtcQuoteRequested;
use App\Domain\Exchange\Exceptions\OtcQuoteException;
use App\Domain\Exchange\Exceptions\TradingDisabledException;
use App\Domain\Exchange\Exceptions\TradingPairHaltedException;
use App\Domain\Exchange\Exceptions\TradingSettlementException;
use App\Domain\Exchange\Models\OtcDeal;
use App\Domain\Exchange\Models\OtcQuote;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Services\LedgerService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * OTC / direct-trade service (model B).
 *
 * Quote -> accept -> settle. Deliberately separate from the order book:
 * it has its own tables (otc_quotes / otc_deals), its own validation,
 * limits and settlement, and it NEVER calls the order-book matcher,
 * the matching workflow, or the order aggregate.
 * Settlement is a direct bilateral exchange with the platform as
 * counterparty, posted as balanced journal entries via
 * LedgerService::post() only — no balance is ever written directly.
 *
 * Pricing: the quote price comes from the MarketDataProvider (ask for a
 * buy, bid for a sell). There is no live provider yet — the sandbox
 * provider's price is labelled as such and is only usable while trading
 * is enabled in a non-production environment.
 */
class OtcTradingService
{
    public function __construct(
        private readonly TradingPairService $pairs,
        private readonly TradingHoldService $holds,
        private readonly MarketDataService $marketData,
        private readonly TradingLimitService $limits,
        private readonly LedgerService $ledger,
        private readonly FeeCalculator $fees,
    ) {
    }

    /** Issue a firm quote, valid for config('trading.otc.quote_ttl_seconds'). */
    public function requestQuote(
        string $accountId,
        string $side,
        string $baseCurrency,
        string $quoteCurrency,
        string $amount,
        ?string $idempotencyKey = null,
    ): OtcQuote {
        $this->assertOtcEnabled();

        $side = strtolower($side);
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw new InvalidArgumentException("Invalid OTC side: {$side}. Expected buy or sell.");
        }
        if (! is_numeric($amount) || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('OTC amount must be a positive decimal string.');
        }

        $pair = $this->pairs->find($baseCurrency, $quoteCurrency);
        if ($pair === null || ! $pair->is_active) {
            throw new InvalidArgumentException("Unknown or inactive trading pair: {$baseCurrency}/{$quoteCurrency}.");
        }
        if ($pair->halted) {
            throw new TradingPairHaltedException("Trading pair is halted: {$baseCurrency}/{$quoteCurrency}.");
        }

        if ($idempotencyKey !== null) {
            if (! Str::isUuid($idempotencyKey)) {
                throw new InvalidArgumentException('Idempotency key must be a UUID.');
            }
            $existing = OtcQuote::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $account = $this->resolveAccount($accountId);
        if ($account === null) {
            throw new InvalidArgumentException('Account not found');
        }

        $baseAsset = Asset::where('code', $baseCurrency)->first();
        $quoteAsset = Asset::where('code', $quoteCurrency)->first();
        if ($baseAsset === null || $quoteAsset === null || ! $baseAsset->is_tradeable || ! $quoteAsset->is_tradeable) {
            throw new InvalidArgumentException('Currency pair not available for trading');
        }

        // Per-asset precision: the amount is in the base asset. A
        // sub-precision amount is refused, never rounded.
        $this->assertDecimalScale($amount, (int) ($baseAsset->precision ?? 18), 'OTC amount');

        $ticker = $this->marketData->getTicker($baseCurrency, $quoteCurrency);
        $price = $side === 'buy' ? $ticker->ask : $ticker->bid;
        $value = BigDecimal::of($amount)->multipliedBy($price)->toScale(18);

        $minValue = (string) config('trading.otc.min_quote_value', '10');
        $maxValue = (string) config('trading.otc.max_quote_value', '100000');
        if ($value->isLessThan($minValue) || $value->isGreaterThan($maxValue)) {
            throw new InvalidArgumentException(
                "OTC quote value {$value->__toString()} {$quoteCurrency} is outside the allowed range {$minValue}–{$maxValue}."
            );
        }

        $rates = $this->fees->ratesForPair($pair);
        $fee = $this->fees->calculateFeeExact($value->__toString(), $rates['taker']);
        $total = $side === 'buy'
            ? $value->plus($fee)
            : $value->minus($fee);

        $quote = null;

        try {
            $quote = OtcQuote::create([
                'quote_id'        => (string) Str::uuid(),
                'account_id'      => $accountId,
                'side'            => $side,
                'base_currency'   => $baseCurrency,
                'quote_currency'  => $quoteCurrency,
                'amount'          => BigDecimal::of($amount)->toScale(18)->__toString(),
                'price'           => $price->toScale(18)->__toString(),
                'fee'             => $fee->toScale(18)->__toString(),
                'total'           => $total->toScale(18)->__toString(),
                'status'          => 'quoted',
                'idempotency_key' => $idempotencyKey,
                'expires_at'      => now()->addSeconds((int) config('trading.otc.quote_ttl_seconds', 60)),
                'metadata'        => ['price_source' => $ticker->exchange, 'source' => 'otc'],
            ]);
        } catch (QueryException $e) {
            // Idempotency race: a concurrent request with the same key
            // committed first. Return the winner's quote (and do not
            // re-emit the requested event for it).
            if ($idempotencyKey !== null && $this->isUniqueViolation($e)) {
                $existing = OtcQuote::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }

        // $created is always true here: a failed create either returned
        // the idempotency winner or re-threw above.
        if ($quote !== null) {
            event(new OtcQuoteRequested(
                quoteId: $quote->quote_id,
                accountId: $accountId,
                side: $side,
                baseCurrency: $baseCurrency,
                quoteCurrency: $quoteCurrency,
                amount: (string) $quote->amount,
                price: (string) $quote->price,
            ));
        }

        return $quote;
    }

    /**
     * Accept a live quote and settle it. Idempotent per quote: accepting
     * twice returns the same deal. Expired quotes can never settle.
     *
     * Durable phases (same rule as order-book settlement):
     *   1. Claim — one transaction: lock the quote, reserve the funds,
     *      create the deal as pending_settlement. No ledger call here.
     *   2. Ledger legs — OUTSIDE any open transaction, via
     *      LedgerService::post(). A leg already posted (same
     *      source_event_id + description) is reused, so a retry after a
     *      partial failure never double-posts. The ledger driver's sync
     *      therefore runs only after the claim committed — never inside
     *      an open transaction.
     *   3. Finalise — one transaction: consume the hold, mark the deal
     *      settled with the entry numbers, mark the quote settled.
     *
     * If phase 2 fails the deal stays pending_settlement/ledger_failed
     * and acceptQuote() throws TradingSettlementException — the deal is
     * NEVER reported settled before settlement is durably recorded.
     * Re-accepting the same quote resumes from the stored deal and
     * completes the missing legs.
     */
    public function acceptQuote(string $quoteId, ?string $accountId = null): OtcDeal
    {
        $this->assertOtcEnabled();

        // Expiry is checked BEFORE the settlement transaction: marking a
        // quote expired inside the transaction would roll back with the
        // exception thrown for the expired quote.
        $preCheck = OtcQuote::where('quote_id', $quoteId)->first();
        if ($preCheck !== null && $preCheck->status === 'quoted' && $preCheck->isExpired()) {
            $preCheck->update(['status' => 'expired']);
            event(new OtcQuoteExpired(quoteId: $quoteId, accountId: (string) $preCheck->account_id));
            throw OtcQuoteException::expired($quoteId);
        }

        $lock = Cache::lock("otc-settlement:{$quoteId}", 60);

        try {
            $lock->block(10);
        } catch (LockTimeoutException $e) {
            throw new TradingSettlementException(
                "OTC quote {$quoteId} is already being settled by another process.",
                0,
                $e,
            );
        }

        try {
            $deal = $this->claimQuote($quoteId, $accountId);

            if ($deal->status === 'settled') {
                return $deal;
            }

            $quote = OtcQuote::where('quote_id', $quoteId)->firstOrFail();

            $entryNumbers = $this->postLegs($quote, $deal);

            $deal = $this->finaliseDeal($quote, $deal, $entryNumbers);
        } finally {
            $lock->release();
        }

        event(new OtcQuoteAccepted(quoteId: $quoteId, accountId: (string) $deal->account_id, dealId: $deal->deal_id));
        event(new OtcDealSettled(
            dealId: $deal->deal_id,
            quoteId: $quoteId,
            accountId: (string) $deal->account_id,
            ledgerEntryNumbers: $deal->ledger_entry_numbers ?? [],
        ));

        return $deal;
    }

    /** Phase 1: lock the quote, reserve funds, create the pending deal. */
    private function claimQuote(string $quoteId, ?string $accountId): OtcDeal
    {
        return DB::transaction(function () use ($quoteId, $accountId): OtcDeal {
            $quote = OtcQuote::where('quote_id', $quoteId)->lockForUpdate()->first();
            if ($quote === null) {
                throw OtcQuoteException::notFound($quoteId);
            }

            // Idempotent re-accept: the deal already exists for this quote.
            // A settled deal returns as-is; a pending/failed one resumes.
            $existingDeal = OtcDeal::where('quote_id', $quoteId)->first();
            if ($existingDeal !== null) {
                return $existingDeal;
            }

            if ($accountId !== null && (string) $quote->account_id !== $accountId) {
                throw new InvalidArgumentException('OTC quote does not belong to this account');
            }
            if ($quote->status !== 'quoted') {
                throw OtcQuoteException::notAcceptable($quoteId, $quote->status);
            }
            if ($quote->isExpired()) {
                // Expired between the pre-check and the lock: refuse to
                // settle. The status is marked by expireQuotes() / the
                // next pre-check; the transaction rolls back cleanly.
                throw OtcQuoteException::expired($quoteId);
            }

            $this->limits->assertCanPlace((string) $quote->account_id, $quote->quote_currency, (string) $quote->total);

            // Validate the legs BEFORE any mutation: a dust quote must
            // fail here, while nothing is recorded — not after the deal
            // exists.
            $this->buildLegs($quote);

            // Reserve the requester's funds (idempotent per quote: a
            // retry returns the same hold).
            $holdAsset = $quote->side === 'buy' ? $quote->quote_currency : $quote->base_currency;
            $holdAmount = $quote->side === 'buy' ? (string) $quote->total : (string) $quote->amount;
            $this->holds->reserve(
                accountId: (string) $quote->account_id,
                assetCode: $holdAsset,
                amount: $holdAmount,
                sourceType: 'otc',
                sourceId: $quote->quote_id,
            );

            $deal = OtcDeal::create([
                'deal_id'              => (string) Str::uuid(),
                'quote_id'             => $quote->quote_id,
                'account_id'           => (string) $quote->account_id,
                'side'                 => $quote->side,
                'base_currency'        => $quote->base_currency,
                'quote_currency'       => $quote->quote_currency,
                'amount'               => (string) $quote->amount,
                'price'                => (string) $quote->price,
                'value'                => BigDecimal::of((string) $quote->amount)->multipliedBy((string) $quote->price)->toScale(18)->__toString(),
                'fee'                  => (string) $quote->fee,
                'status'               => 'pending_settlement',
                'ledger_entry_numbers' => null,
                'settled_at'           => null,
            ]);

            $quote->update(['accepted_at' => now()]);

            return $deal;
        });
    }

    /**
     * Phase 2: post both ledger legs outside any transaction. A leg that
     * was already posted is reused. On failure the deal is durably
     * marked ledger_failed and the exception propagates — the deal is
     * never reported settled.
     *
     * @return array<int, string>
     *
     * @throws TradingSettlementException
     */
    private function postLegs(OtcQuote $quote, OtcDeal $deal): array
    {
        $legs = $this->buildLegs($quote);
        $numbers = [];

        foreach (['base', 'quote'] as $leg) {
            try {
                $numbers[$leg] = $this->postLegOnce($quote->quote_id, $legs[$leg]);
            } catch (TradingSettlementException $e) {
                Log::error('OTC ledger posting failed', [
                    'quote_id' => $quote->quote_id,
                    'deal_id'  => $deal->deal_id,
                    'leg'      => $leg,
                    'posted'   => array_values($numbers),
                    'error'    => $e->getMessage(),
                ]);

                $deal->update(['status' => 'ledger_failed']);

                throw $e;
            }
        }

        return [$numbers['base'], $numbers['quote']];
    }

    /**
     * Post one leg exactly once: reuse the entry when an entry with the
     * same (source_event_id, description) already exists.
     *
     * @param  array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}  $leg
     *
     * @throws TradingSettlementException
     */
    private function postLegOnce(string $quoteId, array $leg): string
    {
        $existing = JournalEntry::where('source_domain', 'exchange')
            ->where('source_event_id', $quoteId)
            ->where('description', $leg['description'])
            ->first();

        if ($existing !== null) {
            return (string) $existing->entry_number;
        }

        try {
            $entry = $this->ledger->post($leg['description'], $leg['lines'], 'exchange', $quoteId);
        } catch (Throwable $e) {
            throw new TradingSettlementException(
                "Ledger posting failed for OTC quote {$quoteId}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        return (string) $entry->entry_number;
    }

    /**
     * Phase 3: consume the hold and mark deal + quote settled.
     *
     * @param  array<int, string>  $entryNumbers
     */
    private function finaliseDeal(OtcQuote $quote, OtcDeal $deal, array $entryNumbers): OtcDeal
    {
        return DB::transaction(function () use ($quote, $deal, $entryNumbers): OtcDeal {
            $locked = OtcDeal::where('deal_id', $deal->deal_id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'settled') {
                return $locked;
            }

            $holdAsset = $quote->side === 'buy' ? $quote->quote_currency : $quote->base_currency;
            $holdAmount = $quote->side === 'buy' ? (string) $quote->total : (string) $quote->amount;
            $hold = $this->holds->activeHoldForSource('otc', $quote->quote_id, $holdAsset);
            if ($hold !== null) {
                $this->holds->consume($hold, $holdAmount);
            }

            $locked->update([
                'status'               => 'settled',
                'ledger_entry_numbers' => $entryNumbers,
                'settled_at'           => now(),
            ]);

            $quote->update(['status' => 'settled', 'settled_at' => now()]);

            return $locked->refresh();
        });
    }

    /** Cancel a still-quoted quote (no funds move; no hold exists yet). */
    public function cancelQuote(string $quoteId, ?string $accountId = null): OtcQuote
    {
        $quote = OtcQuote::where('quote_id', $quoteId)->first();
        if ($quote === null) {
            throw OtcQuoteException::notFound($quoteId);
        }
        if ($accountId !== null && (string) $quote->account_id !== $accountId) {
            throw new InvalidArgumentException('OTC quote does not belong to this account');
        }
        if ($quote->status === 'quoted') {
            $quote->update(['status' => 'cancelled']);
        }

        return $quote->refresh();
    }

    /** Mark all live-but-expired quotes expired. Returns the count. */
    public function expireQuotes(): int
    {
        $expired = OtcQuote::quoted()->where('expires_at', '<=', now())->get();
        foreach ($expired as $quote) {
            $quote->update(['status' => 'expired']);
            event(new OtcQuoteExpired(quoteId: $quote->quote_id, accountId: (string) $quote->account_id));
        }

        return $expired->count();
    }

    public function getQuote(string $quoteId): ?OtcQuote
    {
        return OtcQuote::where('quote_id', $quoteId)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, OtcQuote> */
    public function listQuotes(string $accountId, ?string $status = null)
    {
        $query = OtcQuote::where('account_id', $accountId)->orderByDesc('created_at');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Bilateral settlement legs against the platform as counterparty.
     * Two balanced entries (base leg + quote leg, incl. fee to income),
     * posted via LedgerService only. Pure function of the quote, so the
     * claim phase (validation) and the posting phase always agree.
     *
     * @return array{base: array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}, quote: array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}}
     */
    private function buildLegs(OtcQuote $quote): array
    {
        $base = $quote->base_currency;
        $quoteCcy = $quote->quote_currency;
        $amount = (string) $quote->amount;
        $value = BigDecimal::of($amount)->multipliedBy((string) $quote->price)->toScale(18)->__toString();
        $fee = (string) $quote->fee;

        $baseLeg = BigDecimal::of($amount)->toScale(4, RoundingMode::DOWN)->__toString();
        $valueLeg = BigDecimal::of($value)->toScale(4, RoundingMode::DOWN)->__toString();
        $feeLeg = BigDecimal::of($fee)->toScale(4, RoundingMode::DOWN)->__toString();

        if (BigDecimal::of($baseLeg)->isLessThanOrEqualTo(0) || BigDecimal::of($valueLeg)->isLessThanOrEqualTo(0)) {
            throw new TradingSettlementException(
                "Cannot settle OTC quote {$quote->quote_id} via the ledger: leg amount truncates to zero at 4dp."
            );
        }

        $traderBase = "trading:account:{$quote->account_id}:{$base}";
        $traderQuote = "trading:account:{$quote->account_id}:{$quoteCcy}";
        $platformBase = "trading:otc-platform:{$base}";
        $platformQuote = "trading:otc-platform:{$quoteCcy}";
        $feeIncome = "trading:fees:{$quoteCcy}";

        if ($quote->side === 'buy') {
            // Trader buys base from the platform: trader pays value + fee.
            return [
                'base' => [
                    'description' => "OTC deal {$quote->quote_id}: buy {$amount} {$base} @ {$quote->price} {$quoteCcy}",
                    'lines'       => [
                        ['account_code' => $traderBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "OTC buy {$amount} {$base}"],
                        ['account_code' => $platformBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "OTC sell {$amount} {$base}"],
                    ],
                ],
                'quote' => [
                    'description' => "OTC deal {$quote->quote_id}: pay {$value} {$quoteCcy} + fee {$fee}",
                    'lines'       => [
                        ['account_code' => $traderQuote, 'debit' => BigDecimal::of($valueLeg)->plus($feeLeg)->toScale(4, RoundingMode::DOWN)->__toString(), 'credit' => '0', 'narrative' => "OTC pay {$value} {$quoteCcy} + fee"],
                        ['account_code' => $platformQuote, 'debit' => '0', 'credit' => $valueLeg, 'narrative' => "OTC receive {$value} {$quoteCcy}"],
                        ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $feeLeg, 'narrative' => "OTC fee {$quote->quote_id}"],
                    ],
                ],
            ];
        }

        // Trader sells base to the platform: trader receives value - fee.
        $sellerCredit = BigDecimal::of($valueLeg)->minus($feeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
        if (BigDecimal::of($sellerCredit)->isNegative()) {
            throw new TradingSettlementException("Cannot settle OTC quote {$quote->quote_id}: fee exceeds trade value.");
        }

        return [
            'base' => [
                'description' => "OTC deal {$quote->quote_id}: sell {$amount} {$base} @ {$quote->price} {$quoteCcy}",
                'lines'       => [
                    ['account_code' => $platformBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "OTC buy {$amount} {$base}"],
                    ['account_code' => $traderBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "OTC sell {$amount} {$base}"],
                ],
            ],
            'quote' => [
                'description' => "OTC deal {$quote->quote_id}: receive {$value} {$quoteCcy} - fee {$fee}",
                'lines'       => [
                    ['account_code' => $platformQuote, 'debit' => $valueLeg, 'credit' => '0', 'narrative' => "OTC pay {$value} {$quoteCcy}"],
                    ['account_code' => $traderQuote, 'debit' => '0', 'credit' => $sellerCredit, 'narrative' => "OTC receive {$value} {$quoteCcy} - fee"],
                    ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $feeLeg, 'narrative' => "OTC fee {$quote->quote_id}"],
                ],
            ],
        ];
    }

    /**
     * Reject amounts with more decimal places than the asset's precision
     * allows. Values are never silently rounded: money is exact or refused.
     */
    private function assertDecimalScale(string $value, int $precision, string $what): void
    {
        $plain = BigDecimal::of($value)->toScale(18)->__toString();
        $fraction = str_contains($plain, '.')
            ? substr($plain, (int) strpos($plain, '.') + 1)
            : '';
        $effectiveScale = strlen(rtrim($fraction, '0'));

        if ($effectiveScale > $precision) {
            throw new InvalidArgumentException(
                "{$what} has {$effectiveScale} decimal places, more than the asset precision of {$precision} allows.",
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 covers SQLite, MySQL and Postgres unique violations.
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }

    private function assertOtcEnabled(): void
    {
        if (! (bool) config('trading.enabled', false)) {
            throw new TradingDisabledException(
                'Trading is disabled (TRADING_ENABLED=false). Trading stays disabled until venue/regulatory approval exists.'
            );
        }
        if (! (bool) config('trading.otc.enabled', false)) {
            throw new TradingDisabledException('OTC trading is disabled (TRADING_OTC_ENABLED=false).');
        }
    }

    private function resolveAccount(string $accountId): ?Account
    {
        if (is_numeric($accountId)) {
            $account = Account::find($accountId);
            if ($account !== null) {
                return $account;
            }
        }

        return Account::where('uuid', $accountId)->first();
    }
}
