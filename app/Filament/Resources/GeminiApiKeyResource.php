<?php

namespace App\Filament\Resources;

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
    protected static ?string $model = GeminiApiKey::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'AI API Keys';

    protected static ?string $modelLabel = 'AI API Key';

    protected static ?string $pluralModelLabel = 'AI API Keys';

    protected static ?string $navigationGroup = 'AI Settings';

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
                                'gemini' => 'Gemini',
                                'groq' => 'Groq',
                            ])
                            ->default('gemini')
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
                        'groq' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'gemini' => 'Gemini',
                        'groq' => 'Groq',
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
                        'groq' => 'Groq',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
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
}
