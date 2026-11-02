<?php

namespace App\Filament\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Resources\GeminiApiKeyResource\Pages;
use App\Filament\Resources\GeminiApiKeyResource\RelationManagers\ModelsRelationManager;
use App\Models\GeminiApiKey;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GeminiApiKeyResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use HitlerOnly;

    protected static ?string $model = GeminiApiKey::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'AI API Keys';

    protected static ?string $modelLabel = 'AI API Key';

    protected static ?string $pluralModelLabel = 'AI API Keys';

    protected static ?string $navigationGroup = 'البوت الذكي';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('API Key')
                    ->schema([
                        Forms\Components\Select::make('provider')
                            ->label('Provider')
                            ->options([
                                'openai' => 'GPT (OpenAI) - gpt-5-nano',
                                'gemini' => 'Gemini',
                                'bormaa' => 'Bormaa Codex (external)',
                            ])
                            ->default('openai')
                            ->helperText('Bormaa Codex يسحب الموديلات والاستهلاك تلقائيًا من API بعد الحفظ.')
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('name')
                            ->label('اسم المفتاح')
                            ->placeholder('Account 1')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('api_key')
                            ->label('API Key')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_paid')
                            ->label('مفتاح مدفوع (بيتحسب تكلفته في صفحة التكلفة)'),
                        Forms\Components\TextInput::make('credit_usd')
                            ->label('الرصيد بالدولار')
                            ->numeric(),
                        Forms\Components\DateTimePicker::make('credit_since')
                            ->label('الرصيد بيتحسب من'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('مفعل')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'info',
                        'openai' => 'success',
                        'groq' => 'warning',
                        'bormaa' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'gemini' => 'Gemini',
                        'openai' => 'GPT',
                        'groq' => 'Groq',
                        'bormaa' => 'Bormaa Codex',
                        default => $state ?? '—',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('api_key')
                    ->label('API Key')
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) {
                            return '—';
                        }

                        return substr($state, 0, 8) . '********' . substr($state, -4);
                    })
                    ->copyable()
                    ->copyMessage('API Key copied'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('models_count')
                    ->label('Models')
                    ->counts('models')
                    ->badge(),

                Tables\Columns\TextColumn::make('bormaa_remaining')
                    ->label('Codex Remaining')
                    ->state(fn (GeminiApiKey $record) => $record->isBormaa() ? data_get($record->usage_snapshot, 'quota.remaining') : null)
                    ->suffix(fn (GeminiApiKey $record) => $record->isBormaa() ? '%' : '')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('bormaa_monthly_tokens')
                    ->label('Monthly Tokens')
                    ->state(fn (GeminiApiKey $record) => $record->isBormaa() ? data_get($record->usage_snapshot, 'limits.monthly_tokens.used') : null)
                    ->numeric()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_error')
                    ->label('Last Error')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label('Provider')
                    ->options([
                        'gemini' => 'Gemini',
                        'openai' => 'GPT',
                        'groq' => 'Groq',
                        'bormaa' => 'Bormaa Codex',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_bormaa')
                    ->label('Sync Codex')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (GeminiApiKey $record) => $record->isBormaa())
                    ->action(function (GeminiApiKey $record): void {
                        try {
                            $result = app(\App\Services\BormaaCodexService::class)->sync($record);
                            \Filament\Notifications\Notification::make()->title("تم تحديث {$result['models']} موديل والاستهلاك")->success()->send();
                        } catch (\Throwable $e) {
                            $record->update(['last_error' => mb_substr($e->getMessage(), 0, 2000)]);
                            \Filament\Notifications\Notification::make()->title('فشل تحديث Bormaa Codex')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ModelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeminiApiKeys::route('/'),
            'create' => Pages\CreateGeminiApiKey::route('/create'),
            'edit' => Pages\EditGeminiApiKey::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::isHitler();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        if (($allowed = static::roleAllows('update')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        if (($allowed = static::roleAllows('delete')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }
}
