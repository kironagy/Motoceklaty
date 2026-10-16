<?php

namespace App\Filament\Resources;

use App\Support\FinanceAccess;
use App\Filament\Resources\EmployeeVacationRequestResource\Pages;
use App\Models\EmployeeVacationRequest;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class EmployeeVacationRequestResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = EmployeeVacationRequest::class;

    protected static ?string $navigationLabel = 'طلبات الإجازات';
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! FinanceAccess::isSuper($user)) {
            return null;
        }

        $count = EmployeeVacationRequest::query()
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['staff', 'reviewer']);

        $user = auth()->user();

        if (! FinanceAccess::isSuper($user)) {
            $query->where('staff_id', $user->id);
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        $user = auth()->user();

        return FinanceAccess::isSuper() || FinanceAccess::isShowroomEmployee();
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return FinanceAccess::isShowroomEmployee();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('employee')
                    ->label('الموظف')
                    ->content(fn () => auth()->user()?->name),

                DatePicker::make('from_date')
                    ->label('من تاريخ')
                    ->default(today())
                    ->required(),

                DatePicker::make('to_date')
                    ->label('إلى تاريخ')
                    ->default(today())
                    ->required()
                    ->afterOrEqual('from_date'),

                Select::make('vacation_type')
                    ->label('نوع الإجازة')
                    ->options([
                        'normal' => 'عادية',
                        'emergency' => 'طارئة',
                        'sick' => 'مرضية',
                    ])
                    ->default('normal')
                    ->required(),

                Textarea::make('reason')
                    ->label('سبب الإجازة')
                    ->rows(4),

                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'تحت المراجعة',
                        'approved' => 'تمت الموافقة',
                        'rejected' => 'مرفوضة',
                    ])
                    ->default('pending')
                    ->visible(fn () => FinanceAccess::isSuper())
                    ->required(),

                Textarea::make('admin_notes')
                    ->label('ملاحظات الإدارة')
                    ->visible(fn () => FinanceAccess::isSuper())
                    ->rows(3),
            ]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['staff_id'] = auth()->id();
        $data['status'] = 'pending';

        return $data;
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        if (FinanceAccess::isSuper()) {
            $data['reviewed_by'] = auth()->id();
            $data['reviewed_at'] = now();
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('staff.name')
                    ->label('الموظف')
                    ->visible(fn () => FinanceAccess::isSuper()),

                TextColumn::make('from_date')
                    ->label('من')
                    ->date('Y-m-d'),

                TextColumn::make('to_date')
                    ->label('إلى')
                    ->date('Y-m-d'),

                TextColumn::make('vacation_type')
                    ->label('نوع الإجازة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'normal' => 'عادية',
                        'emergency' => 'طارئة',
                        'sick' => 'مرضية',
                        default => $state,
                    }),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'تحت المراجعة',
                        'approved' => 'تمت الموافقة',
                        'rejected' => 'مرفوضة',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reviewer.name')
                    ->label('تمت بواسطة')
                    ->placeholder('—')
                    ->visible(fn () => FinanceAccess::isSuper()),

                TextColumn::make('created_at')
                    ->label('وقت الطلب')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('عرض / تعديل'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn ($record) =>
                        FinanceAccess::isSuper()
                        || $record->status === 'pending'
                    ),
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
