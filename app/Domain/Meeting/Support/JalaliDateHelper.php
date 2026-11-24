<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Support;

use Carbon\CarbonInterface;

/**
 * Jalali (Persian calendar) display helper.
 *
 * Uses PHP's ext-intl IntlDateFormatter with the Persian calendar
 * (`fa_IR@calendar=persian`) — no calendar library is invented and no
 * new dependency is added; ext-intl is already a required extension.
 * Falls back to a Gregorian date string if intl formatting fails.
 */
final class JalaliDateHelper
{
    /** Convert Latin digits 0-9 to Persian digits. */
    public static function toPersianDigits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $value
        );
    }

    /** Convert Persian/Arabic digits back to Latin digits. */
    public static function toLatinDigits(string $value): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
    }

    /**
     * Format a date in the Jalali calendar, e.g. "۳ آذر ۱۴۰۵، ۱۰:۳۰".
     * Set $persianDigits=false to keep Latin digits.
     *
     * Note: the calendar must be supplied as an IntlCalendar instance —
     * the `fa_IR@calendar=persian` locale keyword alone is ignored by
     * this ICU build, while IntlCalendar::createInstance honours it.
     */
    public static function format(CarbonInterface $date, bool $persianDigits = true): string
    {
        try {
            $calendar = \IntlCalendar::createInstance($date->getTimezone(), 'fa_IR@calendar=persian');
            $formatter = new \IntlDateFormatter(
                'fa_IR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::SHORT,
                $date->getTimezone()->getName(),
                $calendar,
                'd MMMM y، HH:mm'
            );

            $formatted = $formatter->format((int) $date->timestamp);

            if ($formatted === false || $formatted === '') {
                return $date->format('Y-m-d H:i');
            }

            return $persianDigits ? $formatted : self::toLatinDigits($formatted);
        } catch (\Throwable) {
            return $date->format('Y-m-d H:i');
        }
    }

    /** Jalali date only, e.g. "۳ آذر ۱۴۰۵". */
    public static function formatDate(CarbonInterface $date, bool $persianDigits = true): string
    {
        try {
            $calendar = \IntlCalendar::createInstance($date->getTimezone(), 'fa_IR@calendar=persian');
            $formatter = new \IntlDateFormatter(
                'fa_IR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                $date->getTimezone()->getName(),
                $calendar,
                'd MMMM y'
            );

            $formatted = $formatter->format((int) $date->timestamp);

            if ($formatted === false || $formatted === '') {
                return $date->format('Y-m-d');
            }

            return $persianDigits ? $formatted : self::toLatinDigits($formatted);
        } catch (\Throwable) {
            return $date->format('Y-m-d');
        }
    }
}
