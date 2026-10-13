<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\WebsiteSettings;
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

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationGroup = 'محتوى الموقع';

    protected static ?int $navigationSort = 22;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم العميل')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('position')
                    ->label('الوصف تحت الاسم')
                    ->placeholder('مثال: مالك سكوتر فورزا')
                    ->maxLength(255),

                Forms\Components\Textarea::make('review')
                    ->label('التوصية / الرأي')
                    ->required()
                    ->rows(5)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('image')
                    ->label('صورة العميل')
                    ->image()
                    ->avatar()
                    ->disk('public')
                    ->directory('site/testimonials')
                    ->imageEditor(),

                Forms\Components\Select::make('rating')
                    ->label('التقييم')
                    ->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★'])
                    ->default(5)
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->label('يظهر على الموقع')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('')->circular()
                    ->getStateUsing(fn (ClientReview $record) => $record->imageUrl()),
                Tables\Columns\TextColumn::make('name')->label('اسم العميل')->searchable()
                    ->description(fn (ClientReview $record) => $record->position),
                Tables\Columns\TextColumn::make('review')
                    ->label('التوصية')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('rating')->label('التقييم')
                    ->formatStateUsing(fn ($state) => str_repeat('★', (int) $state)),
                Tables\Columns\ToggleColumn::make('is_active')->label('ظاهر'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
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
