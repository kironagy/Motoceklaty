<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineResource\Pages;
use App\Models\Brand;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MachineResource extends Resource
{
    protected static ?string $model = Machine::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'المكن';
    protected static ?string $pluralLabel = 'المكن';
    protected static ?string $label = 'مكينة';
    protected static ?string $navigationGroup = 'المعرض';

public static function form(Form $form): Form
{
    return $form->schema([
        // 🟡 بيانات المكنة الأساسية
        Forms\Components\Section::make('بيانات المكنة')
            ->schema([
                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم المكنة')
                            ->required(),

                        Forms\Components\Select::make('brand_id')
                            ->label('النوع')
                            ->options(Brand::pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        Forms\Components\Select::make('type')
                            ->label('نوع المكنة')
                            ->options([
                                'normal' => 'عادية',
                                'offer'  => 'عرض خاص',
                            ])
                            ->required()
                            ->default('normal')
                            ->reactive(), // 🔹 يخلي الفورم يتفاعل لحظيًا مع التغيير
                    ]),

                Forms\Components\FileUpload::make('display_image')
                    ->label('صورة العرض العامة')
                    ->image()
                    ->directory('machines/display')
                    ->imageEditor()
                    ->panelLayout('compact')
                    ->columnSpanFull(),
            ])
            ->columns(1),

        // ⚙️ مميزات المكنة
        Forms\Components\Section::make('مميزات المكنة')
            ->schema([
                Forms\Components\Repeater::make('features')
                    ->label('المميزات')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('اسم الميزة')
                            ->required()
                            ->placeholder('اكتب اسم الميزة هنا...'),
                    ])
                    ->createItemButtonLabel('إضافة ميزة جديدة')
                    ->collapsed(false)
                    ->columnSpanFull(),
            ])
            ->columns(1),

        // 🎨 تفاصيل الألوان وصور المكنة
        Forms\Components\Section::make('تفاصيل الألوان وصور المكنة')
            ->schema([
                Forms\Components\Repeater::make('colors')
    ->label('ألوان المكنة')
    ->schema([

        Forms\Components\ColorPicker::make('color')
            ->label('اللون')
            ->required(),

        Forms\Components\Grid::make(2)
            ->schema([

                Forms\Components\FileUpload::make('color_display')
                    ->label('صورة العرض الخاصة باللون')
                    ->image()
                    ->directory('machines/colors')
                    ->preserveFilenames(false)
                    ->getUploadedFileNameForStorageUsing(fn($file) =>
                        'color_display_' . uniqid() . '.' . $file->getClientOriginalExtension()
                    )
                    ->nullable()
                    ->panelLayout('compact'),

                Forms\Components\FileUpload::make('images')
                    ->label('صور المكنة لهذا اللون')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->directory('machines/gallery')
                    ->preserveFilenames(false)
                    ->getUploadedFileNameForStorageUsing(fn($file) =>
                        'gallery_' . uniqid() . '.' . $file->getClientOriginalExtension()
                    )
                    ->panelLayout('compact'),
            ]),
    ])
    ->default([])
    ->columnSpanFull(),

            ])
            ->columns(1),

        // 💰 الأسعار وأنظمة التقسيط
        Forms\Components\Section::make('الأسعار والتقسيط')
            ->schema([
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('cash_price')
                            ->label('سعر الكاش')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('installment_price')
                            ->label('سعر القسط')
                            ->numeric()
                            ->required(),
                    ]),

                // 🟢 الأسعار الخاصة بالعروض (تظهر فقط لما النوع = offer)
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('old_price')
                            ->label('السعر قبل الخصم')
                            ->numeric()
                            ->visible(fn ($get) => $get('type') === 'offer')
                            ->required(fn ($get) => $get('type') === 'offer'),

                        Forms\Components\TextInput::make('new_price')
                            ->label('السعر بعد الخصم')
                            ->numeric()
                            ->visible(fn ($get) => $get('type') === 'offer')
                            ->required(fn ($get) => $get('type') === 'offer'),
                    ]),

                Forms\Components\CheckboxList::make('installment_systems')
                    ->label('أنظمة التقسيط')
                    ->options(InstallmentSystem::pluck('name', 'id'))
                    ->columns(3)
                    ->bulkToggleable(true)
                    ->columnSpanFull(),
            ])
            ->columns(1),
    ]);
}

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المكنة')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('brand.name')
                    ->label('النوع')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('display_image')
                    ->label('صورة العرض العامة'),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع المكنة')
                    ->badge()
                    ->color(fn($state) => $state === 'offer' ? 'success' : 'gray')
                    ->formatStateUsing(fn($state) => $state === 'offer' ? 'عرض خاص' : 'عادية'),

                Tables\Columns\TextColumn::make('cash_price')
                    ->label('سعر الكاش')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('installment_price')
                    ->label('سعر القسط')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('new_price')
                    ->label('سعر العرض')
                    ->money('EGP')
                    ->visible(fn($record) => $record && $record->type === 'offer'),
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


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachines::route('/'),
            'create' => Pages\CreateMachine::route('/create'),
            'edit' => Pages\EditMachine::route('/{record}/edit'),
        ];
    }
}
