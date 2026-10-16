<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;
use App\Models\EmployeeAdvanceRequest;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class EmployeeAdvanceRequestResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    protected static ?string $model = EmployeeAdvanceRequest::class;

    protected static ?string $navigationLabel = 'طلبات السلف';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'view');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! static::fullAccess()) {
            return null;
        }

        $count = \App\Models\EmployeeAdvanceRequest::query()
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

        if (! static::fullAccess()) {
            $query->where('staff_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('employee')
                    ->label('الموظف')
                    ->content(fn () => auth()->user()->name),

                TextInput::make('amount')
                    ->label('قيمة السلفة')
                    ->numeric()
                    ->prefix('ج')
                    ->required()
                    ->minValue(1),

                DatePicker::make('request_date')
                    ->label('تاريخ السلفة')
                    ->default(today())
                    ->required(),

                Textarea::make('reason')
                    ->label('سبب السلفة')
                    ->rows(4),

                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'تحت المراجعة',
                        'approved' => 'تمت الموافقة',
                        'rejected' => 'مرفوضة',
                    ])
                    ->default('pending')
                    ->visible(fn () => static::fullAccess())
                    ->required()
                    ->live(),

                Textarea::make('admin_notes')
                    ->label('ملاحظات الإدارة')
                    ->visible(fn () => static::fullAccess())
                    ->rows(3),
            ]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['staff_id'] = auth()->id();

        return $data;
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        if (static::fullAccess()) {
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
                    ->visible(fn () => static::fullAccess()),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP'),

                TextColumn::make('request_date')
                    ->label('التاريخ')
                    ->date('Y-m-d'),

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
                    ->visible(fn () => static::fullAccess()),

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
                        static::fullAccess()
                        || $record->status === 'pending'
                    ),
            ]);
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'create');
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
