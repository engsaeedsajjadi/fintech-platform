<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Support;

use Carbon\CarbonInterface;
use IntlDateFormatter;
use Throwable;

/**
 * Locale-aware date display (display only — storage stays UTC datetimes).
 *
 * - `fa`: Jalali calendar with Persian digits (via JalaliDateHelper).
 * - any other locale: Gregorian calendar via IntlDateFormatter.
 *
 * Previous behaviour always rendered Jalali, even for `en` users. Callers
 * pass the active locale (or null to use the app locale).
 */
final class LocalizedDateHelper
{
    public static function format(CarbonInterface $date, ?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        if ($locale === 'fa') {
            return JalaliDateHelper::format($date);
        }

        return self::gregorian($date, 'd MMMM y, HH:mm', $locale);
    }

    public static function formatDate(CarbonInterface $date, ?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        if ($locale === 'fa') {
            return JalaliDateHelper::formatDate($date);
        }

        return self::gregorian($date, 'd MMMM y', $locale);
    }

    private static function gregorian(CarbonInterface $date, string $pattern, string $locale): string
    {
        try {
            $formatter = new IntlDateFormatter(
                $locale === 'fa' ? 'fa_IR' : $locale,
                IntlDateFormatter::FULL,
                IntlDateFormatter::SHORT,
                $date->getTimezone()->getName(),
                IntlDateFormatter::GREGORIAN,
                $pattern
            );

            $formatted = $formatter->format((int) $date->timestamp);

            if ($formatted === false || $formatted === '') {
                return $date->format('Y-m-d H:i');
            }

            return $formatted;
        } catch (Throwable) {
            return $date->format('Y-m-d H:i');
        }
    }
}
