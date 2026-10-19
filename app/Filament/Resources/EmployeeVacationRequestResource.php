<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeVacationRequestResource\Pages;
use App\Models\EmployeeVacationRequest;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeVacationRequestResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    use \App\Filament\Concerns\ReviewsStaffRequests;

    protected static ?string $model = EmployeeVacationRequest::class;

    protected static ?string $navigationLabel = 'طلبات الإجازات';
    protected static ?string $modelLabel = 'طلب إجازة';
    protected static ?string $pluralModelLabel = 'طلبات الإجازات';
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    public const TYPES = [
        'normal' => 'عادية',
        'emergency' => 'طارئة',
        'sick' => 'مرضية',
    ];

    protected static function requestNoun(): string
    {
        return 'طلب الإجازة';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::fullAccess()) {
            return null;
        }

        $count = EmployeeVacationRequest::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['staff', 'reviewer']);

        if (! static::fullAccess()) {
            $query->where('staff_id', auth()->id());
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::fullAccess() || \App\Support\Access::allows(static::class, 'create');
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'create');
    }

    public static function days($from, $to): int
    {
        return $from && $to ? Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 : 0;
    }

    /** Approved vacation days of the employee in that year, the request itself left out. */
    public static function yearDays(?int $staffId, $date, ?int $except = null): int
    {
        $year = rescue(fn () => Carbon::parse($date)->year, now()->year, false);

        return EmployeeVacationRequest::query()
            ->where('staff_id', $staffId)->where('status', 'approved')
            ->whereYear('from_date', $year)
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))
            ->get()->sum(fn ($v) => static::days($v->from_date, $v->to_date));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                View::make('filament.resources.staff-requests.hero')
                    ->viewData(['kind' => 'vacation'])
                    ->visible(fn ($record) => blank($record))
                    ->columnSpanFull(),

                View::make('filament.resources.staff-requests.summary')
                    ->viewData(['kind' => 'vacation'])
                    ->visible(fn ($record) => filled($record))
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make(fn ($record) => $record ? 'تفاصيل الإجازة' : 'طلب إجازة جديد')
                            ->description(fn ($record) => $record && ! static::canChangeDetails($record)
                                ? 'الطلب اتراجع، مينفعش يتعدل. لو محتاج تغيير اعمل طلب جديد.'
                                : 'اختار النوع والأيام، والكارت اللي جنبك بيتحدث معاك.')
                            ->icon('heroicon-o-calendar-days')
                            ->disabled(fn ($record) => ! static::canChangeDetails($record))
                            ->schema([
                                ToggleButtons::make('vacation_type')
                                    ->label('نوع الإجازة')
                                    ->options(static::TYPES)
                                    ->icons(['normal' => 'heroicon-o-sun', 'emergency' => 'heroicon-o-bolt', 'sick' => 'heroicon-o-heart'])
                                    ->colors(['normal' => 'info', 'emergency' => 'warning', 'sick' => 'danger'])
                                    ->default('normal')
                                    ->inline()
                                    ->live()
                                    ->required()
                                    ->columnSpanFull(),

                                DatePicker::make('from_date')
                                    ->label('من تاريخ')
                                    ->prefixIcon('heroicon-m-arrow-right-start-on-rectangle')
                                    ->default(today())
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->closeOnDateSelection()
                                    ->live()
                                    ->required(),

                                DatePicker::make('to_date')
                                    ->label('إلى تاريخ')
                                    ->prefixIcon('heroicon-m-arrow-left-end-on-rectangle')
                                    ->default(today())
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->closeOnDateSelection()
                                    ->live()
                                    ->required()
                                    ->afterOrEqual('from_date'),

                                Textarea::make('reason')
                                    ->label('سبب الإجازة')
                                    ->placeholder('مثال: سفر مع العيلة / ظرف طارئ في البيت / كشف عند الدكتور')
                                    ->rows(4)
                                    ->autosize()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpan(['lg' => 2]),

                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get, $record) => view('filament.resources.staff-requests.vacation-preview', [
                                'type' => $get('vacation_type'),
                                'from' => $get('from_date'),
                                'to' => $get('to_date'),
                                'yearDays' => static::yearDays($record?->staff_id ?? auth()->id(), $get('from_date'), $record?->id),
                            ]))
                            ->columnSpan(['lg' => 1]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('staff.name')
                    ->label('الموظف')
                    ->searchable()
                    ->visible(fn () => static::fullAccess()),

                TextColumn::make('from_date')
                    ->label('من')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('to_date')
                    ->label('إلى')
                    ->date('Y-m-d'),

                TextColumn::make('days')
                    ->label('أيام')
                    ->state(fn ($record) => static::days($record->from_date, $record->to_date)),

                TextColumn::make('vacation_type')
                    ->label('نوع الإجازة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => static::TYPES[$state] ?? $state),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => static::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => static::STATUS_COLORS[$state] ?? 'gray'),

                TextColumn::make('reviewer.name')
                    ->label('تمت بواسطة')
                    ->placeholder('—')
                    ->visible(fn () => static::fullAccess()),

                TextColumn::make('created_at')
                    ->label('وقت الطلب')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(static::STATUSES),
            ])
            ->actions([
                ...static::decisionActions(Tables\Actions\Action::class),

                Tables\Actions\EditAction::make()
                    ->label(fn ($record) => static::canChangeDetails($record) ? 'عرض / تعديل' : 'عرض')
                    ->icon(fn ($record) => static::canChangeDetails($record) ? 'heroicon-o-pencil-square' : 'heroicon-o-eye'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn ($record) => static::fullAccess()
                        || ((int) $record->staff_id === (int) auth()->id() && $record->status === 'pending')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeVacationRequests::route('/'),
            'create' => Pages\CreateEmployeeVacationRequest::route('/create'),
            'edit' => Pages\EditEmployeeVacationRequest::route('/{record}/edit'),
        ];
    }
}
