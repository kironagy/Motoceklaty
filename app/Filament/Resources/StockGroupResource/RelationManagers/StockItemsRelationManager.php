<?php

namespace App\Filament\Resources\StockGroupResource\RelationManagers;

use App\Models\Answer;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;

class StockItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'وحدات المخزن';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات المكنة')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('color')
                        ->label('اللون')
                        ->maxLength(100),

                    Forms\Components\FileUpload::make('chassis_image')
                        ->label('صورة الشاسيه')
                        ->image()
                        ->disk('public')
                        ->directory('stock/chassis')
                        ->downloadable()
                        ->openable()
                        ->maxSize(4096),

                    Forms\Components\FileUpload::make('engine_image')
                        ->label('صورة الماتور')
                        ->image()
                        ->disk('public')
                        ->directory('stock/engine')
                        ->downloadable()
                        ->openable()
                        ->maxSize(4096),
                ]),

            Forms\Components\Section::make('بيانات العميل (تظهر عند التعديل)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('customer_name')
                        ->label('الاسم'),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('رقم التليفون')
                        ->tel(),

                    Forms\Components\FileUpload::make('id_front_image')
                        ->label('صورة البطاقة (وش)')
                        ->image()
                        ->disk('public')
                        ->directory('stock/id-front')
                        ->downloadable()
                        ->openable()
                        ->maxSize(4096),

                    Forms\Components\FileUpload::make('id_back_image')
                        ->label('صورة البطاقة (ضهر)')
                        ->image()
                        ->disk('public')
                        ->directory('stock/id-back')
                        ->downloadable()
                        ->openable()
                        ->maxSize(4096),

                    Forms\Components\TextInput::make('remaining_amount')
                        ->label('المبلغ المتبقي')
                        ->numeric()
                        ->prefix('EGP')
                        ->rules(['nullable', 'numeric', 'min:0']),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('color')->label('اللون')->searchable(),

                Tables\Columns\ImageColumn::make('chassis_image')->label('الشاسيه')->disk('public')->square()->height(40),
                Tables\Columns\ImageColumn::make('engine_image')->label('الماتور')->disk('public')->square()->height(40),

                Tables\Columns\TextColumn::make('customer_name')->label('اسم العميل')->toggleable(),
                Tables\Columns\TextColumn::make('customer_phone')->label('تليفون')->toggleable(),

                Tables\Columns\TextColumn::make('remaining_amount')
                    ->label('المتبقي')
                    ->money('EGP', locale: 'ar_EG')
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة وحدة')
                    ->after(function () {
                        // بعد الإضافة: تحديث الكمية المتاحة في المجموعة
                        $this->getOwnerRecord()->refreshAvailableQuantity();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),

                Tables\Actions\Action::make('to_answers')
                    ->label('إضافة إلى الجوابات')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $group = $this->getOwnerRecord();
                        $machineName = $group->machine?->name;

                        Answer::create([
                            'name' => $record->customer_name ?? '',
                            'phone' => $record->customer_phone ?? '',
                            'machine_name' => $machineName ?? '',
                            'chassis_image' => $record->chassis_image,
                            'engine_image' => $record->engine_image,
                            'id_front_image' => $record->id_front_image,
                            'id_back_image' => $record->id_back_image,
                            'remaining_amount' => $record->remaining_amount,
                            'received_from_raed' => false,
                            'delivered_to_customer' => false,
                        ]);

                        // حذفها من المخزن
                        $record->delete();

                        // تحديث المتاح
                        $group->refreshAvailableQuantity();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->after(function () {
                        $this->getOwnerRecord()->refreshAvailableQuantity();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

