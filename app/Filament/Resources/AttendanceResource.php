<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Domain\Attendance\AttendancePoints;
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
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AttendanceResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
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

        return static::fullAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['staff', 'staff.branch', 'point']);

        $user = auth()->user();

        // Without full access an employee sees only his own days.
        if (! static::fullAccess()) {
            $query->where('staff_id', $user?->id);
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
                            ->when(! static::fullAccess(), fn ($q) => $q->whereKey($auth->id))
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

                // Worked out from the check-in time when the day is saved (AttendancePoints::apply).
                TextInput::make('late_minutes')
                    ->label('دقائق التأخير')
                    ->helperText('بتتحسب لوحدها من وقت الحضور وميعاد الموظف.')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('deduction_amount')
                    ->label('قيمة الخصم')
                    ->prefix('ج')
                    ->helperText('حمرا 200 ج، سودا 1000 ج، وبتتشال لو النقطة اتلغت.')
                    ->disabled()
                    ->dehydrated(false),

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

                TextColumn::make('staff.branch.name')
                    ->label('الفرع')
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

                TextColumn::make('point.color')
                    ->label('النقطة')
                    ->badge()
                    ->formatStateUsing(fn ($state, Attendance $record) => (AttendancePoints::ICONS[$state] ?? '').' '.match ($state) {
                        AttendancePoints::GREEN => 'خضرا',
                        AttendancePoints::RED => 'حمرا',
                        AttendancePoints::BLACK => 'سودا',
                        default => $state,
                    }.match ($record->point?->status) {
                        AttendancePoints::USED => ' (اتستخدمت)',
                        AttendancePoints::CANCELED => ' (اتلغت)',
                        default => '',
                    })
                    ->color(fn ($state, Attendance $record) => $record->point?->status === AttendancePoints::ACTIVE
                        ? match ($state) {
                            AttendancePoints::GREEN => 'success',
                            AttendancePoints::RED => 'danger',
                            default => 'gray',
                        }
                        : 'gray')
                    ->placeholder('—'),

                TextColumn::make('overtime_minutes')
                    ->label('وقت زيادة')
                    ->formatStateUsing(fn ($state) => $state ? \App\Filament\Widgets\AttendanceCheckWidget::hoursText((int) $state) : null)
                    ->placeholder('—')
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

                SelectFilter::make('point')
                    ->label('النقطة')
                    ->options([
                        AttendancePoints::GREEN => 'خضرا',
                        AttendancePoints::RED => 'حمرا',
                        AttendancePoints::BLACK => 'سودا',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($q, $color) => $q->whereHas('point', fn ($p) => $p->where('color', $color)))),

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
                    ->visible(fn () => static::fullAccess()),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => static::fullAccess()),
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
