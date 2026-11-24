<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Models\PostingRule;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use App\Support\DecimalString;

final class PostingRuleEngine
{
    /**
     * Find and execute posting rules for a given event.
     *
     * Amount contract: `amount_expression` values are MAJOR-unit decimals
     * at the posting scale — except `event.amount_minor`, which is an
     * integer minor-unit amount converted with the event currency (the
     * Account domain's Money object carries minor units with no currency
     * of its own, so it must never be read as `event.amount`: that would
     * mis-post cents as whole units, 100x off).
     *
     * @param  array<string, mixed> $eventData — must contain 'amount' key at minimum
     * @return array<int, array{debit_account: string, credit_account: string, amount: string, currency: string}>
     */
    public function resolveRules(string $eventName, array $eventData): array
    {
        $scale = (int) config('ledger.posting_scale', 4);
        $currency = strtoupper((string) ($eventData['currency'] ?? config('ledger.default_currency', 'USD')));
        $rules = PostingRule::active()->forEvent($eventName)->orderBy('priority')->get();
        $entries = [];

        foreach ($rules as $rule) {
            $amount = $this->resolveAmount($rule->amount_expression, $eventData, $currency);
            $numericAmount = DecimalString::fromNumeric($amount, $scale) ?? '0';

            if (bccomp($numericAmount, '0', $scale) > 0) {
                $entries[] = [
                    'debit_account'  => $rule->debit_account,
                    'credit_account' => $rule->credit_account,
                    'amount'         => $numericAmount,
                    'currency'       => $currency,
                ];
            }
        }

        return $entries;
    }

    /**
     * Resolve an amount expression against event data.
     *
     * Supports: 'event.amount' (major units), 'event.amount_minor'
     * (integer minor units, converted with the event currency),
     * 'event.fee', 'event.interest', or literal numeric strings.
     *
     * The returned string is the raw major-unit value; resolveRules()
     * normalises it to the posting scale.
     *
     * @param  array<string, mixed> $eventData
     */
    private function resolveAmount(string $expression, array $eventData, string $currency = 'USD'): string
    {
        if (str_starts_with($expression, 'event.')) {
            $key = substr($expression, 6);
            $raw = $eventData[$key] ?? '0';

            if ($key === 'amount_minor') {
                if (! is_numeric($raw)) {
                    return '0';
                }

                return MoneyAmount::fromMinorUnits((int) $raw, $currency)->decimal;
            }

            return $this->scalarAmount($raw);
        }

        // Literal numeric value
        if (is_numeric($expression)) {
            return $expression;
        }

        return '0';
    }

    /**
     * Coerce an event-data value to a major-unit decimal string. Arrays
     * shaped like ['amount' => …] (e.g. a serialised Money object) resolve
     * to their amount; anything else that is not a plain number is zero —
     * never a silently invented amount.
     */
    private function scalarAmount(mixed $raw): string
    {
        if (is_array($raw) && isset($raw['amount']) && is_numeric($raw['amount'])) {
            $raw = $raw['amount'];
        }

        if (is_object($raw) && method_exists($raw, 'getAmount') && is_numeric($raw->getAmount())) {
            // A Money-like object: its amount is in MINOR units, so it is
            // deliberately NOT usable as a major-unit 'event.amount'. The
            // listener exposes it as 'amount_minor' instead.
            return '0';
        }

        if (! is_numeric($raw)) {
            return '0';
        }

        return (string) $raw;
    }
}
