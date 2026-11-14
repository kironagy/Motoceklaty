<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeDeductionResource\Pages;
use App\Models\EmployeeDeduction;
use App\Models\Staff;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeDeductionResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = EmployeeDeduction::class;

    protected static ?string $navigationLabel = 'الخصومات';
    protected static ?string $modelLabel = 'خصم';
    protected static ?string $pluralModelLabel = 'الخصومات';
    protected static ?string $navigationIcon = 'heroicon-o-minus-circle';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    // HR money tab: staff without a role get nothing (same as the salaries page).
    public static function canViewAny(): bool
    {
        return static::roleAllows('view') ?? \App\Support\Access::allows(static::class, 'view');
    }

    public static function canCreate(): bool
    {
        return static::roleAllows('create') ?? \App\Support\Access::allows(static::class, 'create');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['staff', 'creator']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('بيانات الخصم')
                ->description('الخصم بيتحسب بعدد الأيام × أجر اليوم (المرتب ÷ 30) ويتخصم من مرتب الشهر بتاع تاريخ الخصم.')
                ->icon('heroicon-o-minus-circle')
                ->schema([
                    Select::make('staff_id')
                        ->label('الموظف')
                        ->options(fn () => Staff::query()->where('is_showroom_employee', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    TextInput::make('days')
                        ->label('عدد الأيام')
                        ->numeric()
                        ->minValue(0.5)
                        ->step(0.5)
                        ->suffix('يوم')
                        ->formatStateUsing(fn ($state) => is_numeric($state) ? $state + 0 : $state)
                        ->live(debounce: 400)
                        ->required(),

                    DatePicker::make('deduction_date')
                        ->label('تاريخ الخصم')
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->required(),

                    Placeholder::make('preview')
                        ->label('قيمة الخصم')
                        ->content(function (Get $get) {
                            $staff = $get('staff_id') ? Staff::find($get('staff_id')) : null;
                            $rate = EmployeeDeduction::dayRate($staff);
                            $days = is_numeric($get('days')) ? (float) $get('days') : 0;

                            if (! $staff) {
                                return 'اختار الموظف الأول';
                            }

                            return number_format($days * $rate, 2) . ' ج  (أجر اليوم ' . number_format($rate, 2) . ' ج)';
                        }),

                    Textarea::make('reason')
                        ->label('السبب')
                        ->rows(3)
                        ->autosize()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('deduction_date', 'desc')
            ->columns([
                TextColumn::make('staff.name')->label('الموظف')->searchable()->sortable(),
                TextColumn::make('days')->label('الأيام')
                    ->formatStateUsing(fn ($state) => ($state + 0) . ' يوم')->sortable(),
                TextColumn::make('amount')->label('المبلغ')->money('EGP')->sortable(),
                TextColumn::make('deduction_date')->label('التاريخ')->date('Y-m-d')->sortable(),
                TextColumn::make('reason')->label('السبب')->limit(40)->placeholder('—'),
                TextColumn::make('creator.name')->label('بواسطة')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('staff_id')->label('الموظف')
                    ->relationship('staff', 'name')->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeDeductions::route('/'),
            'create' => Pages\CreateEmployeeDeduction::route('/create'),
            'edit' => Pages\EditEmployeeDeduction::route('/{record}/edit'),
        ];
    }
}
