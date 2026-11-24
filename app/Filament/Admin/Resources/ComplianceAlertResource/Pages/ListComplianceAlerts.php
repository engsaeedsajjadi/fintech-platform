<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ComplianceAlertResource\Pages;

use App\Filament\Admin\Resources\ComplianceAlertResource;
use Filament\Resources\Pages\ListRecords;

class ListComplianceAlerts extends ListRecords
{
    protected static string $resource = ComplianceAlertResource::class;
}
