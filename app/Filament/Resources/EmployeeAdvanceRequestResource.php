<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;
use App\Models\EmployeeAdvanceRequest;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeAdvanceRequestResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    use \App\Filament\Concerns\ReviewsStaffRequests;

    protected static ?string $model = EmployeeAdvanceRequest::class;

    protected static ?string $navigationLabel = 'طلبات السلف';
    protected static ?string $modelLabel = 'طلب سلفة';
    protected static ?string $pluralModelLabel = 'طلبات السلف';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    protected static function requestNoun(): string
    {
        return 'طلب السلفة';
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'view');
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'create');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::fullAccess()) {
            return null;
        }

        $count = EmployeeAdvanceRequest::query()->where('status', 'pending')->count();

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

    /** Approved advances of the employee in that month, the request itself left out. */
    public static function monthApproved(?int $staffId, $date, ?int $except = null): float
    {
        $month = rescue(fn () => \Carbon\Carbon::parse($date), now(), false);

        return (float) EmployeeAdvanceRequest::query()
            ->where('staff_id', $staffId)->where('status', 'approved')
            ->whereBetween('request_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))
            ->sum('amount');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                View::make('filament.resources.staff-requests.hero')
                    ->viewData(['kind' => 'advance'])
                    ->visible(fn ($record) => blank($record))
                    ->columnSpanFull(),

                View::make('filament.resources.staff-requests.summary')
                    ->viewData(['kind' => 'advance'])
                    ->visible(fn ($record) => filled($record))
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make(fn ($record) => $record ? 'تفاصيل السلفة' : 'طلب سلفة جديد')
                            ->description(fn ($record) => $record && ! static::canChangeDetails($record)
                                ? 'الطلب اتراجع، مينفعش يتعدل. لو محتاج تغيير اعمل طلب جديد.'
                                : 'الطلب بيروح للإدارة تراجعه، وهيوصلك إشعار بالقرار.')
                            ->icon('heroicon-o-banknotes')
                            ->disabled(fn ($record) => ! static::canChangeDetails($record))
                            ->schema([
                                TextInput::make('amount')
                                    ->label('قيمة السلفة')
                                    ->numeric()
                                    ->prefixIcon('heroicon-m-banknotes')
                                    ->suffix('جنيه')
                                    ->placeholder('0')
                                    ->formatStateUsing(fn ($state) => is_numeric($state) ? $state + 0 : $state)
                                    ->live(debounce: 400)
                                    ->required()
                                    ->minValue(1)
                                    ->extraInputAttributes(['style' => 'font-size: 1.25rem; font-weight: 800']),

                                DatePicker::make('request_date')
                                    ->label('تاريخ السلفة')
                                    ->prefixIcon('heroicon-m-calendar')
                                    ->default(today())
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->closeOnDateSelection()
                                    ->live()
                                    ->required(),

                                Textarea::make('reason')
                                    ->label('سبب السلفة')
                                    ->placeholder('مثال: مصاريف مدارس / علاج / ظرف طارئ')
                                    ->rows(4)
                                    ->autosize()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpan(['lg' => 2]),

                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(function (Get $get, $record) {
                                $staff = $record?->staff ?? auth()->user();
                                $date = $get('request_date') ?: today();

                                return view('filament.resources.staff-requests.advance-preview', [
                                    'amount' => is_numeric($get('amount')) ? $get('amount') : 0,
                                    'salary' => (float) ($staff?->monthly_salary ?? 0),
                                    'monthApproved' => static::monthApproved($staff?->id, $date, $record?->id),
                                    'month' => rescue(fn () => \Carbon\Carbon::parse($date)->locale('ar')->translatedFormat('F Y'), '', false),
                                    'editable' => static::canChangeDetails($record),
                                ]);
                            })
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

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('request_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

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
            'index' => Pages\ListEmployeeAdvanceRequests::route('/'),
            'create' => Pages\CreateEmployeeAdvanceRequest::route('/create'),
            'edit' => Pages\EditEmployeeAdvanceRequest::route('/{record}/edit'),
        ];
    }
}
