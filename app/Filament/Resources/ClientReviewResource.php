<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientReviewResource\Pages;
use App\Models\ClientReview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables;

class ClientReviewResource extends Resource
{
    protected static ?string $model = ClientReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = 'آراء العملاء';
    protected static ?string $pluralLabel = 'آراء العملاء';
    protected static ?string $modelLabel = 'رأي عميل';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم العميل')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('review')
                    ->label('التوصية / الرأي')
                    ->required()
                    ->rows(5)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('اسم العميل')->searchable(),
                Tables\Columns\TextColumn::make('review')
                    ->label('التوصية')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientReviews::route('/'),
            'create' => Pages\CreateClientReview::route('/create'),
            'edit' => Pages\EditClientReview::route('/{record}/edit'),
        ];
    }
}
