<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiMemoryResource\Pages;
use App\Models\AiMemory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiMemoryResource extends Resource
{
    protected static ?string $model = AiMemory::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'WhatsApp AI';

    protected static ?string $navigationLabel = 'ذاكرة الذكاء الاصطناعي';

    protected static ?string $modelLabel = 'ميموري';

    protected static ?string $pluralModelLabel = 'الميموري';

    protected static ?int $navigationSort = 1;

public static function form(Form $form): Form
{
    return $form
        ->schema([

            Forms\Components\Section::make('بيانات الميموري')
                ->schema([

                    Forms\Components\TextInput::make('title')
                        ->label('العنوان')
                        ->required(),

                    Forms\Components\Textarea::make('content')
                        ->label('المحتوى')
                        ->rows(20)
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('template_replies')
                        ->label('ردود جاهزة بصيغ مختلفة')
                        ->schema([
                            Forms\Components\Textarea::make('reply')
                                ->label('نص الرد')
                                ->rows(4)
                                ->required()
                                ->helperText('اكتب صيغة رد مختلفة. تقدر تستخدم متغيرات زي {machine_name} و {cash_price} و {installment_price}.'),
                        ])
                        ->addActionLabel('إضافة رد')
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->defaultItems(0)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('مفعلة')
                        ->default(true),

                    Forms\Components\TextInput::make('sort')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(10),

                ])
                ->columns(2),

        ]);
}    
public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([

                Tables\Columns\TextColumn::make('sort')
                    ->label('الترتيب')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable(),
Tables\Columns\TextColumn::make('template_replies_count')
    ->label('عدد الردود')
    ->state(fn ($record) => is_array($record->template_replies) ? count($record->template_replies) : 0),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('الحالة')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->dateTime('Y-m-d h:i A'),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiMemories::route('/'),
            'create' => Pages\CreateAiMemory::route('/create'),
            'edit' => Pages\EditAiMemory::route('/{record}/edit'),
        ];
    }
}

