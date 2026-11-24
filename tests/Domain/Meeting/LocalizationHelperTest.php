<?php

declare(strict_types=1);

use App\Domain\Meeting\Support\LocalizedDateHelper;
use App\Domain\Meeting\Support\LocalizedNumberHelper;
use Carbon\Carbon;

it('formats dates in Jalali with Persian digits for fa and Gregorian for en', function () {
    // 2026-11-24 Gregorian = 3 Azar 1405 Jalali.
    $date = Carbon::create(2026, 11, 24, 10, 30, 0, 'Asia/Tehran');

    expect(LocalizedDateHelper::format($date, 'fa'))->toContain('۱۴۰۵')
        ->and(LocalizedDateHelper::format($date, 'fa'))->toContain('آذر')
        ->and(LocalizedDateHelper::format($date, 'en'))->toContain('November')
        ->and(LocalizedDateHelper::format($date, 'en'))->toContain('2026')
        ->and(LocalizedDateHelper::format($date, 'en'))->not->toContain('۱۴۰۵');
});

it('formats dates for the active app locale by default', function () {
    $date = Carbon::create(2026, 11, 24, 10, 30, 0, 'Asia/Tehran');

    app()->setLocale('fa');
    expect(LocalizedDateHelper::format($date))->toContain('آذر');

    app()->setLocale('en');
    expect(LocalizedDateHelper::format($date))->toContain('November');
});

it('formats numbers with Persian digits for fa and Latin digits for en', function () {
    expect(LocalizedNumberHelper::formatNumber('1234567.50', 'fa'))->toContain('۱')
        ->and(LocalizedNumberHelper::formatNumber('1234567.50', 'en'))->toBe('1,234,567.50')
        ->and(LocalizedNumberHelper::formatCount(1234, 'fa'))->toContain('۱٬۲۳۴')
        ->and(LocalizedNumberHelper::formatCount(1234, 'en'))->toBe('1,234');
});

it('formats currency for display only and never changes the stored value', function () {
    $stored = '2500000.00';

    $fa = LocalizedNumberHelper::formatCurrency($stored, 'IRR', 'fa');
    $en = LocalizedNumberHelper::formatCurrency($stored, 'IRR', 'en');

    expect($fa)->toContain('۲٬۵۰۰٬۰۰۰')
        ->and($en)->toContain('2,500,000')
        ->and($stored)->toBe('2500000.00'); // storage precision untouched
});

it('resolves the fa validation messages instead of falling back to English', function () {
    app()->setLocale('fa');

    expect(__('validation.required', ['attribute' => 'title']))->toBe('فیلد title الزامی است.')
        ->and(__('validation.email', ['attribute' => 'email']))->toBe('فیلد email باید یک نشانی ایمیل معتبر باشد.')
        ->and(__('auth.failed'))->toBe('این اطلاعات با سوابق ما مطابقت ندارد.')
        ->and(__('pagination.next'))->toBe('بعدی &raquo;')
        ->and(__('meeting.conflict'))->toBe('میزبان یا یکی از شرکت‌کنندگان در آن زمان جلسه دیگری دارد.');
});
