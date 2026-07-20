<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SliderResource\Pages;
use App\Models\Slider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SliderResource extends Resource
{
    protected static ?string $model = Slider::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'السلايدر';
    protected static ?string $pluralLabel = 'السلايدر';
    protected static ?string $label = 'عنصر سلايدر';
    protected static ?string $navigationGroup = 'الواجهة الرئيسية';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات السلايدر')->schema([
                    Forms\Components\Select::make('type')
                        ->label('نوع العنصر')
                        ->options([
                            'image' => 'صورة',
                            'video' => 'فيديو',
                        ])
                        ->default('image')
                        ->reactive()
                        ->required(),

                    Forms\Components\FileUpload::make('file_path')
                        ->label('رفع الصورة أو الفيديو')
                        ->disk('public')
                        ->directory('sliders')
                        ->visibility('public')
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/jpg',
                            'image/webp',
                            'video/mp4',
                            'video/webm',
                            'video/ogg',
                        ])
                        ->previewable(fn(callable $get) => $get('type') !== 'video') // لو حابب تفضل المعاينة للصور فقط
                        ->downloadable()
                        ->openable()
                        ->maxSize(512000) // 500 ميجا
                        ->required(),


                    Forms\Components\TextInput::make('title')
                        ->label('العنوان')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->label('الوصف')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->colors([
                        'primary' => 'image',
                        'success' => 'video',
                    ])
                    ->formatStateUsing(fn($state) => $state === 'video' ? 'فيديو' : 'صورة'),

                Tables\Columns\ImageColumn::make('file_path')
                    ->label('المعاينة')
                    ->disk('public')
                    ->square(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSliders::route('/'),
            'create' => Pages\CreateSlider::route('/create'),
            'edit' => Pages\EditSlider::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (!$user) return true;

        return $user->is_admin ?? false;
    }
}
