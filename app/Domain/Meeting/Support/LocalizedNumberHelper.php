<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Support;

use NumberFormatter;
use Throwable;

/**
 * Locale-aware number / currency *display* formatting.
 *
 * Display only: input amounts are strings exactly as stored (never a
 * float, never re-rounded) and this helper never changes stored values,
 * storage precision, or the internal currency representation. It only
 * renders a stored decimal string for a human in the active locale:
 * Persian digits + fa_IR grouping for `fa`, Latin digits for `en`.
 */
final class LocalizedNumberHelper
{
    /**
     * Format a stored decimal string for display, e.g. "1234567.50" →
     * fa: "۱٬۲۳۴٬۵۶۷٫۵" / en: "1,234,567.5". The stored string is untouched.
     */
    public static function formatNumber(string $value, ?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        if ($locale !== 'fa') {
            return number_format((float) $value, self::decimals($value), '.', ',');
        }

        try {
            $formatter = new NumberFormatter('fa_IR', NumberFormatter::DECIMAL);
            $formatted = $formatter->format((float) $value);
            if ($formatted === false) {
                return JalaliDateHelper::toPersianDigits(number_format((float) $value, self::decimals($value), '.', ','));
            }

            return $formatted;
        } catch (Throwable) {
            return JalaliDateHelper::toPersianDigits(number_format((float) $value, self::decimals($value), '.', ','));
        }
    }

    /** Format an integer count for display, e.g. 1234 → fa: "۱٬۲۳۴". */
    public static function formatCount(int $value, ?string $locale = null): string
    {
        return self::formatNumber((string) $value, $locale);
    }

    /**
     * Format a stored amount + ISO currency code for display only,
     * e.g. ("2500000", "IRR", "fa") → "۲٬۵۰۰٬۰۰۰ ریال".
     */
    public static function formatCurrency(string $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        try {
            $formatter = new NumberFormatter($locale === 'fa' ? 'fa_IR' : 'en_US', NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $amount, strtoupper($currency));
            if ($formatted !== false) {
                return $formatted;
            }
        } catch (Throwable) {
            // Fall through to the plain rendering below.
        }

        return self::formatNumber($amount, $locale) . ' ' . strtoupper($currency);
    }

    private static function decimals(string $value): int
    {
        $pos = strrpos($value, '.');

        return $pos === false ? 0 : strlen($value) - $pos - 1;
    }
}
