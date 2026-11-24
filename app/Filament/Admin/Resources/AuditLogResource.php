<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\AuditLogResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit log viewer. Audit records are written by privileged
 * actions (e.g. MeetingService admin cancel, KycService verify/reject)
 * and must never be edited or deleted from the admin panel.
 */
class AuditLogResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('action')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Actor')->searchable(),
                Tables\Columns\TextColumn::make('auditable_type')->label('Subject type')->toggleable(),
                Tables\Columns\TextColumn::make('auditable_id')->label('Subject id')->toggleable(),
                Tables\Columns\TextColumn::make('tags')->searchable(),
                Tables\Columns\TextColumn::make('ip_address')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('tags')
                    ->label('Tag')
                    ->options([
                        'meeting,admin' => 'Meeting admin',
                        'kyc,compliance,verification' => 'KYC verification',
                        'kyc,compliance,rejection' => 'KYC rejection',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
