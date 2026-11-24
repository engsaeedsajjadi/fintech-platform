<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Exceptions\ExchangeRateUnavailableException;
use App\Domain\International\Models\ExchangeQuotation;
use App\Domain\International\ValueObjects\ExchangeRate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Exchange quotations for cross-currency transfers.
 *
 * There is NO live FX feed in this codebase. A quotation is created
 * only with a rate explicitly supplied by an operator / treasury desk
 * (source `manual`, recorded on the row) — no rate is ever invented or
 * fetched from an unconfigured provider. Quotes expire after their TTL
 * (default 15 minutes); an expired or mismatched quote can never fund
 * a transfer.
 */
final class QuotationService
{
    public function __construct(
        private readonly InternationalAuditService $audit,
    ) {
    }

    public function createQuotation(
        User $user,
        string $sourceCurrency,
        string $destinationCurrency,
        int $amountMinor,
        string $rate,
        int $ttlMinutes = 15,
    ): ExchangeQuotation {
        $sourceCurrency = strtoupper($sourceCurrency);
        $destinationCurrency = strtoupper($destinationCurrency);

        if (
            ! InternationalAccountService::isSupportedCurrency($sourceCurrency)
            || ! InternationalAccountService::isSupportedCurrency($destinationCurrency)
        ) {
            throw new InvalidArgumentException('Both quotation currencies must be supported international currencies.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Quotation amount must be a positive integer in minor units.');
        }

        if ($ttlMinutes <= 0) {
            throw new InvalidArgumentException('Quotation TTL must be positive.');
        }

        $exchangeRate = new ExchangeRate($sourceCurrency, $destinationCurrency, $rate);

        $quotation = ExchangeQuotation::create([
            'user_id'                  => $user->id,
            'source_currency'          => $sourceCurrency,
            'destination_currency'     => $destinationCurrency,
            'amount_minor'             => $amountMinor,
            'rate'                     => $exchangeRate->rate,
            'destination_amount_minor' => $exchangeRate->convertMinor($amountMinor),
            'source'                   => 'manual',
            'status'                   => 'quoted',
            'expires_at'               => Carbon::now()->addMinutes($ttlMinutes),
        ]);

        $this->audit->log(null, (int) $user->id, 'quotation.created', [
            'quotation_id' => $quotation->id,
            'pair'         => "{$sourceCurrency}/{$destinationCurrency}",
            'rate'         => $exchangeRate->rate,
        ]);

        return $quotation;
    }

    public function acceptQuotation(ExchangeQuotation $quotation, User $user): ExchangeQuotation
    {
        if ((int) $quotation->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Quotation does not belong to this user.');
        }

        if ($quotation->status !== 'quoted') {
            throw new InvalidArgumentException("Only a quoted quotation can be accepted (status: {$quotation->status}).");
        }

        if ($quotation->isExpired()) {
            $quotation->update(['status' => 'expired']);

            throw new ExchangeRateUnavailableException('Quotation has expired; request a new rate.');
        }

        $quotation->update(['status' => 'accepted', 'accepted_at' => now()]);

        return $quotation->refresh();
    }

    /**
     * Load the quotation a cross-currency transfer must use, enforcing
     * ownership, accepted + unexpired status, and an exact match of
     * currencies and amount. Anything else fails closed.
     */
    public function usableQuotationFor(
        User $user,
        string $quotationId,
        string $sourceCurrency,
        string $destinationCurrency,
        int $amountMinor,
    ): ExchangeQuotation {
        $quotation = ExchangeQuotation::find($quotationId);

        if ($quotation === null || (int) $quotation->user_id !== (int) $user->id) {
            throw new ExchangeRateUnavailableException('Quotation not found for this user.');
        }

        if (! $quotation->isUsable()) {
            throw new ExchangeRateUnavailableException('Quotation is not accepted or has expired; request a new rate.');
        }

        if (
            $quotation->source_currency !== strtoupper($sourceCurrency)
            || $quotation->destination_currency !== strtoupper($destinationCurrency)
            || (int) $quotation->amount_minor !== $amountMinor
        ) {
            throw new ExchangeRateUnavailableException('Quotation does not match this transfer (currency or amount).');
        }

        return $quotation;
    }

    /** Mark due quotations expired. Returns the number expired. */
    public function expireDue(): int
    {
        return DB::table('exchange_quotations')
            ->where('status', 'quoted')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }
}
