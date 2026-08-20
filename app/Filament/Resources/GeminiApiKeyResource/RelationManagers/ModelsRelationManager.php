<?php

namespace App\Filament\Resources\GeminiApiKeyResource\RelationManagers;

use App\Models\GeminiApiKeyModel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ModelsRelationManager extends RelationManager
{
    protected static string $relationship = 'models';

    protected static ?string $title = 'Models Usage';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Model Data')
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->label('Display Name')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('model_code')
                            ->label('Model Code')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('category')
                            ->label('Category')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Toggle::make('is_embedding')
                            ->label('Embedding Model')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active'),

                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Limits')
                    ->schema([
                        Forms\Components\TextInput::make('rpm_limit')
                            ->label('RPM Limit')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('rpd_limit')
                            ->label('RPD Limit')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('tps_limit')
                            ->label('TPS Limit')
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Monitoring')
                    ->schema([
                        Forms\Components\TextInput::make('requests_today')
                            ->label('Requests Today')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('requests_this_minute')
                            ->label('Requests This Minute')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('tokens_this_second')
                            ->label('Tokens This Second')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('last_used_at')
                            ->label('Last Used At')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('cooldown_until')
                            ->label('Cooldown Until')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Textarea::make('last_error')
                            ->label('Last Error')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Model')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('model_code')
                    ->label('Code')
                    ->copyable()
                    ->limit(35),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_embedding')
                    ->label('Embedding')
                    ->boolean(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rpm_limit')
                    ->label('RPM'),

                Tables\Columns\TextColumn::make('rpd_limit')
                    ->label('RPD'),

                Tables\Columns\TextColumn::make('tps_limit')
                    ->label('TPS'),

                Tables\Columns\TextColumn::make('requests_today')
                    ->label('Used Today')
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_today')
                    ->label('Remaining')
                    ->state(fn (GeminiApiKeyModel $record): int => $record->remaining_today)
                    ->badge()
                    ->color(fn (GeminiApiKeyModel $record): string => match (true) {
                        $record->remaining_today <= 0 => 'danger',
                        $record->remaining_today <= 50 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('requests_this_minute')
                    ->label('Used Minute'),

                Tables\Columns\TextColumn::make('tokens_this_second')
                    ->label('Tokens/Sec'),

                Tables\Columns\TextColumn::make('cooldown_until')
                    ->label('Cooldown')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->since()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_error')
                    ->label('Error')
                    ->limit(35)
                    ->tooltip(fn (GeminiApiKeyModel $record): ?string => $record->last_error)
                    ->color('danger')
                    ->placeholder('—'),
            ])
            ->headerActions([
                // ممنوع إضافة مودلز يدويًا
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('reset_usage')
                    ->label('Reset')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (GeminiApiKeyModel $record) {
                        $record->update([
                            'requests_today' => 0,
                            'requests_this_minute' => 0,
                            'tokens_this_second' => 0,
                            'minute_window_started_at' => now(),
                            'second_window_started_at' => now(),
                            'cooldown_until' => null,
                            'last_error' => null,
                        ]);

                        Notification::make()
                            ->title('تم تصفير استهلاك الموديل')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
