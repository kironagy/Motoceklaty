<?php

namespace App\Filament\Resources;

use App\Support\FinanceAccess;
use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use App\Models\Staff;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class AttendanceResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationLabel = 'الحضور والانصراف';
    protected static ?string $pluralModelLabel = 'الحضور والانصراف';
    protected static ?string $modelLabel = 'بصمة';
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        $user = auth()->user();

        return FinanceAccess::isSuper();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['staff', 'staff.warehouse']);

        $user = auth()->user();

        if (FinanceAccess::isAdmin($user)) {
            $query->whereHas('staff', function (Builder $q) use ($user) {
                $q->where('warehouse_id', $user->warehouse_id);
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('staff_id')
                    ->label('الموظف')
                    ->options(function () {
                        $auth = auth()->user();

                        return Staff::query()
                            ->where('is_showroom_employee', true)
                            ->when(FinanceAccess::isAdmin($auth), fn ($q) => $q->where('warehouse_id', $auth->warehouse_id))
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('attendance_date')
                    ->label('تاريخ اليوم')
                    ->required(),

                DateTimePicker::make('check_in_at')
                    ->label('وقت الحضور')
                    ->seconds(false),

                DateTimePicker::make('check_out_at')
                    ->label('وقت الانصراف')
                    ->seconds(false),

                TextInput::make('late_minutes')
                    ->label('دقائق التأخير')
                    ->numeric()
                    ->default(0),

                TextInput::make('deduction_days')
                    ->label('أيام الخصم')
                    ->numeric()
                    ->default(0),

                TextInput::make('deduction_amount')
                    ->label('قيمة الخصم')
                    ->numeric()
                    ->prefix('ج')
                    ->default(0),

                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'late' => 'متأخر',
                        'absent' => 'غياب',
                        'vacation' => 'إجازة',
                        'manual' => 'تعديل يدوي',
                    ])
                    ->default('present')
                    ->required(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('attendance_date', 'desc')
            ->columns([
                TextColumn::make('attendance_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('staff.name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('staff.warehouse.name')
                    ->label('المعرض')
                    ->placeholder('—'),

                TextColumn::make('check_in_at')
                    ->label('الحضور')
                    ->dateTime('h:i A')
                    ->placeholder('—'),

                TextColumn::make('check_out_at')
                    ->label('الانصراف')
                    ->dateTime('h:i A')
                    ->placeholder('—'),

                TextColumn::make('late_minutes')
                    ->label('التأخير')
                    ->suffix(' دقيقة')
                    ->sortable(),

                TextColumn::make('deduction_days')
                    ->label('أيام الخصم')
                    ->sortable(),

                TextColumn::make('deduction_amount')
                    ->label('قيمة الخصم')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'present' => 'حاضر',
                        'late' => 'متأخر',
                        'absent' => 'غياب',
                        'vacation' => 'إجازة',
                        'manual' => 'تعديل يدوي',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'present' => 'success',
                        'late' => 'warning',
                        'absent' => 'danger',
                        'vacation' => 'info',
                        'manual' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('staff_id')
                    ->label('الموظف')
                    ->options(fn () => Staff::query()
                        ->where('is_showroom_employee', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'present' => 'حاضر',
                        'late' => 'متأخر',
                        'absent' => 'غياب',
                        'vacation' => 'إجازة',
                        'manual' => 'تعديل يدوي',
                    ]),

                Filter::make('attendance_date')
                    ->label('فترة التاريخ')
                    ->form([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('attendance_date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('attendance_date', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('تعديل'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn () => FinanceAccess::isSuper()),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => FinanceAccess::isSuper()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }
}
