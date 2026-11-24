<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\ComplianceAlert;
use App\Filament\Admin\Resources\ComplianceAlertResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * AML / compliance alert review queue. Alerts are acknowledged here;
 * the underlying screening verdicts live in the Compliance domain.
 * Every acknowledge action writes an audit record.
 */
class ComplianceAlertResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = ComplianceAlert::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('alert_id')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('type')->searchable(),
                Tables\Columns\TextColumn::make('severity')->badge()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (ComplianceAlert $record): string => $record->getStatusColor())
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('source')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ComplianceAlert::STATUS_OPEN => 'Open',
                        ComplianceAlert::STATUS_IN_REVIEW => 'In review',
                        ComplianceAlert::STATUS_ESCALATED => 'Escalated',
                        ComplianceAlert::STATUS_RESOLVED => 'Resolved',
                        ComplianceAlert::STATUS_FALSE_POSITIVE => 'False positive',
                        ComplianceAlert::STATUS_EXPIRED => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('severity')
                    ->options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('acknowledge')
                    ->label('Take in review')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (ComplianceAlert $record): bool => $record->status === ComplianceAlert::STATUS_OPEN)
                    ->action(function (ComplianceAlert $record): void {
                        $oldStatus = $record->status;
                        $record->update([
                            'status' => ComplianceAlert::STATUS_IN_REVIEW,
                            'status_changed_at' => now(),
                            'status_changed_by' => auth()->user()?->uuid,
                        ]);

                        AuditLog::log(
                            'compliance.alert.acknowledged',
                            $record,
                            ['status' => $oldStatus],
                            ['status' => ComplianceAlert::STATUS_IN_REVIEW],
                            ['reviewed_by' => auth()->user()?->uuid],
                            'compliance,aml'
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplianceAlerts::route('/'),
            'view' => Pages\ViewComplianceAlert::route('/{record}'),
        ];
    }
}
