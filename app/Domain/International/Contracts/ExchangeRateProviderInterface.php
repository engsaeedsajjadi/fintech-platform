<?php

declare(strict_types=1);

namespace App\Domain\International\Contracts;

use App\Domain\International\ValueObjects\ExchangeRate;

/**
 * FX rate provider integration point.
 *
 * No live FX feed exists in this codebase. The default binding is
 * ManualRateProvider (see QuotationService): a quotation is only
 * created with a rate explicitly supplied by an operator / treasury
 * desk, and the source is recorded as `manual`. A production provider
 * (licensed market-data feed) implements this interface.
 */
interface ExchangeRateProviderInterface
{
    /**
     * @throws \App\Domain\International\Exceptions\ExchangeRateUnavailableException
     *         when no rate can be supplied for the pair.
     */
    public function rateFor(string $sourceCurrency, string $destinationCurrency): ExchangeRate;

    public function getName(): string;
}
