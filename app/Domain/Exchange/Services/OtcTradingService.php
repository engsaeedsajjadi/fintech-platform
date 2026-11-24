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
use App\Domain\Ledger\Services\LedgerService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

        event(new OtcQuoteRequested(
            quoteId: $quote->quote_id,
            accountId: $accountId,
            side: $side,
            baseCurrency: $baseCurrency,
            quoteCurrency: $quoteCurrency,
            amount: (string) $quote->amount,
            price: (string) $quote->price,
        ));

        return $quote;
    }

    /**
     * Accept a live quote and settle it. Idempotent per quote: accepting
     * twice returns the same deal. Expired quotes can never settle.
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

        $deal = DB::transaction(function () use ($quoteId, $accountId): OtcDeal {
            $quote = OtcQuote::where('quote_id', $quoteId)->lockForUpdate()->first();
            if ($quote === null) {
                throw OtcQuoteException::notFound($quoteId);
            }

            // Idempotent re-accept: the deal already exists for this quote.
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

            // Reserve the requester's funds, then settle, then consume.
            $holdAsset = $quote->side === 'buy' ? $quote->quote_currency : $quote->base_currency;
            $holdAmount = $quote->side === 'buy' ? (string) $quote->total : (string) $quote->amount;
            $hold = $this->holds->reserve(
                accountId: (string) $quote->account_id,
                assetCode: $holdAsset,
                amount: $holdAmount,
                sourceType: 'otc',
                sourceId: $quote->quote_id,
            );

            $entryNumbers = $this->settleQuote($quote);

            $this->holds->consume($hold, $holdAmount);

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
                'status'               => 'settled',
                'ledger_entry_numbers' => $entryNumbers,
                'settled_at'           => now(),
            ]);

            $quote->update(['status' => 'settled', 'accepted_at' => now(), 'settled_at' => now()]);

            return $deal;
        });

        event(new OtcQuoteAccepted(quoteId: $quoteId, accountId: (string) $deal->account_id, dealId: $deal->deal_id));
        event(new OtcDealSettled(
            dealId: $deal->deal_id,
            quoteId: $quoteId,
            accountId: (string) $deal->account_id,
            ledgerEntryNumbers: $deal->ledger_entry_numbers ?? [],
        ));

        return $deal;
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
     * Bilateral settlement against the platform as counterparty.
     * Two balanced entries (base leg + quote leg, incl. fee to income),
     * posted via LedgerService only. Returns journal entry numbers.
     *
     * @return array<int, string>
     */
    private function settleQuote(OtcQuote $quote): array
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
            $baseEntry = $this->ledger->post(
                "OTC deal {$quote->quote_id}: buy {$amount} {$base} @ {$quote->price} {$quoteCcy}",
                [
                    ['account_code' => $traderBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "OTC buy {$amount} {$base}"],
                    ['account_code' => $platformBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "OTC sell {$amount} {$base}"],
                ],
                'exchange',
                $quote->quote_id,
            );
            $quoteEntry = $this->ledger->post(
                "OTC deal {$quote->quote_id}: pay {$value} {$quoteCcy} + fee {$fee}",
                [
                    ['account_code' => $traderQuote, 'debit' => BigDecimal::of($valueLeg)->plus($feeLeg)->toScale(4, RoundingMode::DOWN)->__toString(), 'credit' => '0', 'narrative' => "OTC pay {$value} {$quoteCcy} + fee"],
                    ['account_code' => $platformQuote, 'debit' => '0', 'credit' => $valueLeg, 'narrative' => "OTC receive {$value} {$quoteCcy}"],
                    ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $feeLeg, 'narrative' => "OTC fee {$quote->quote_id}"],
                ],
                'exchange',
                $quote->quote_id,
            );
        } else {
            // Trader sells base to the platform: trader receives value - fee.
            $sellerCredit = BigDecimal::of($valueLeg)->minus($feeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
            if (BigDecimal::of($sellerCredit)->isNegative()) {
                throw new TradingSettlementException("Cannot settle OTC quote {$quote->quote_id}: fee exceeds trade value.");
            }
            $baseEntry = $this->ledger->post(
                "OTC deal {$quote->quote_id}: sell {$amount} {$base} @ {$quote->price} {$quoteCcy}",
                [
                    ['account_code' => $platformBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "OTC buy {$amount} {$base}"],
                    ['account_code' => $traderBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "OTC sell {$amount} {$base}"],
                ],
                'exchange',
                $quote->quote_id,
            );
            $quoteEntry = $this->ledger->post(
                "OTC deal {$quote->quote_id}: receive {$value} {$quoteCcy} - fee {$fee}",
                [
                    ['account_code' => $platformQuote, 'debit' => $valueLeg, 'credit' => '0', 'narrative' => "OTC pay {$value} {$quoteCcy}"],
                    ['account_code' => $traderQuote, 'debit' => '0', 'credit' => $sellerCredit, 'narrative' => "OTC receive {$value} {$quoteCcy} - fee"],
                    ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $feeLeg, 'narrative' => "OTC fee {$quote->quote_id}"],
                ],
                'exchange',
                $quote->quote_id,
            );
        }

        return [$baseEntry->entry_number, $quoteEntry->entry_number];
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
