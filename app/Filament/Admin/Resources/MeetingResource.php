<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Meeting\Models\Meeting;
use App\Domain\Meeting\Services\MeetingService;
use App\Filament\Admin\Resources\MeetingResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MeetingResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = Meeting::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Meetings';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('host_uuid')
                    ->label('Host')
                    ->relationship('host', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options([
                        'video_kyc' => 'Video KYC',
                        'support' => 'Support',
                        'advisory' => 'Advisory',
                        'onboarding' => 'Onboarding',
                    ])
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'confirmed' => 'Confirmed',
                        'in_progress' => 'In progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])
                    ->required(),
                Forms\Components\DateTimePicker::make('starts_at')->required(),
                Forms\Components\DateTimePicker::make('ends_at')->required(),
                Forms\Components\Select::make('provider')
                    ->options(['sandbox' => 'Sandbox (dev only)', 'jitsi' => 'Jitsi Meet'])
                    ->required(),
                Forms\Components\TextInput::make('room_name')->disabled(),
                Forms\Components\TextInput::make('join_url')->disabled(),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
                Forms\Components\Textarea::make('cancel_reason')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('host.name')->label('Host')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('provider')->badge(),
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('participants_count')->counts('participants')->label('Participants'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'confirmed' => 'Confirmed',
                        'in_progress' => 'In progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'video_kyc' => 'Video KYC',
                        'support' => 'Support',
                        'advisory' => 'Advisory',
                        'onboarding' => 'Onboarding',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Meeting $record): bool => $record->status->value === 'scheduled')
                    ->action(function (Meeting $record): void {
                        $record->update(['status' => 'confirmed']);
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel meeting')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Meeting $record): bool => $record->status->isActive())
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Cancellation reason')->maxLength(500),
                    ])
                    // Privileged admin action: MeetingService writes an
                    // AuditLog record when the actor is not the host.
                    ->action(function (Meeting $record, array $data): void {
                        app(MeetingService::class)->cancel($record, auth()->user(), $data['reason'] ?? null);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetings::route('/'),
            'view' => Pages\ViewMeeting::route('/{record}'),
            'edit' => Pages\EditMeeting::route('/{record}/edit'),
        ];
    }
}
