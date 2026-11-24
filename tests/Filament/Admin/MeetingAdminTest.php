<?php

declare(strict_types=1);

use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Support\JalaliDateHelper;
use App\Filament\Admin\Resources\AuditLogResource;
use App\Filament\Admin\Resources\ComplianceAlertResource;
use App\Filament\Admin\Resources\MeetingResource;
use Carbon\Carbon;
use Filament\Facades\Filament;

uses(Tests\TestCase::class);

it('registers the new admin resources in the admin panel', function () {
    $resources = Filament::getPanel('admin')->getResources();

    expect($resources)->toContain(MeetingResource::class)
        ->and($resources)->toContain(AuditLogResource::class)
        ->and($resources)->toContain(ComplianceAlertResource::class);
});

it('meeting resource is bound to the meeting model', function () {
    expect(MeetingResource::getModel())->toBe(Meeting::class)
        ->and(MeetingResource::getNavigationGroup())->toBe('Meetings');
});

it('audit log resource is read-only in the admin panel', function () {
    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit(new \App\Domain\Compliance\Models\AuditLog))->toBeFalse()
        ->and(AuditLogResource::canDelete(new \App\Domain\Compliance\Models\AuditLog))->toBeFalse();
});

it('formats dates in the Jalali calendar with Persian digits', function () {
    // 2026-11-24 Gregorian = 3 Azar 1405 Jalali.
    $formatted = JalaliDateHelper::format(Carbon::create(2026, 11, 24, 10, 30, 0, 'Asia/Tehran'));

    expect($formatted)->toContain('۱۴۰۵')
        ->and($formatted)->toContain('آذر');
});

it('converts digits between Persian and Latin', function () {
    expect(JalaliDateHelper::toPersianDigits('1405-09-03'))->toBe('۱۴۰۵-۰۹-۰۳')
        ->and(JalaliDateHelper::toLatinDigits('۱۴۰۵-۰۹-۰۳'))->toBe('1405-09-03');
});
