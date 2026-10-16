<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Get;
use App\Models\InstallmentSystem;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
class StaffResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Staff::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?string $label = 'موظف';
    protected static ?string $pluralLabel = 'الموظفين';
    protected static ?string $navigationLabel = 'الموظفين';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(' الاسم ')
                ->required(),

            Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->required(),

            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->columnSpan('full'),

Forms\Components\Toggle::make('is_admin')
    ->label('هل أدمن؟')
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('السوبر أدمن فقط يستطيع تعديل هذه الصلاحية.'),

Forms\Components\Toggle::make('is_super_admin')
    ->label('هل سوبر أدمن؟')
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('لا يمكن للسوبر أدمن تعديل صلاحيات حسابه بنفسه.'),
Forms\Components\Toggle::make('is_hitler')
    ->label('هل هذا القائد المغوار الفحل العنتيل الرهيب حاتم الحاتم ابو حاتم هتلر؟')
    ->default(false)
    ->visible(fn () => auth()->user()?->is_hitler),
Forms\Components\Toggle::make('is_bot')
    ->label('هل هذا الموظف بوت؟')
    ->default(false)
->visible(fn () => auth()->user()?->is_hitler)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('السوبر أدمن أو موظف هتلر فقط يستطيع تحديد حسابات البوت.'),

Forms\Components\Toggle::make('is_company_employee')
    ->label('هل موظف شركة؟')
    ->default(false)
    ->live()
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->afterStateUpdated(function ($state, callable $set) {
        if (! $state) {
            $set('installmentSystems', []);
        }
    })
    ->helperText('السوبر أدمن فقط يستطيع ربط الموظف بشركات التقسيط.'),

Forms\Components\Select::make('installmentSystems')
    ->label('تابع للشركات')
    ->relationship('installmentSystems', 'name')
    ->multiple()
    ->searchable()
    ->preload()
    ->visible(fn (Get $get) =>
        auth()->user()?->is_super_admin &&
        $get('is_company_employee')
    )
    ->required(fn (Get $get) =>
        auth()->user()?->is_super_admin &&
        (bool) $get('is_company_employee')
    )
    ->columnSpan('full'), 

            // Dashboard roles (App\Support\Access): a role, or custom permissions of his own that replace it.
            Forms\Components\Section::make('الدور والصلاحيات في الداشبورد')
                ->description('من غير دور ولا صلاحيات مخصصة الموظف بيشوف زي ما كان قبل كده.')
                ->visible(fn (?Staff $record) => static::canManageAccess($record))
                ->schema([
                    Forms\Components\Select::make('role_id')
                        ->label('الدور')
                        ->relationship('role', 'name')
                        ->preload()
                        ->nullable()
                        ->live(),
                    Forms\Components\Toggle::make('custom_permissions')
                        ->label('صلاحيات مخصصة للموظف ده')
                        ->helperText('لما تتفعّل، الصلاحيات اللي تحت هي اللي بتتطبق بدل صلاحيات الدور.')
                        ->live()
                        ->afterStateHydrated(fn (Forms\Components\Toggle $component, ?Staff $record) => $component->state($record?->permissions !== null))
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set) {
                            // Start from the chosen role so only the differences need ticking.
                            if ($state && blank(array_filter((array) $get('permissions')))) {
                                $set('permissions', \App\Models\Role::find($get('role_id'))?->permissions ?? []);
                            }
                        }),
                    \App\Filament\Support\PermissionMatrix::field()
                        ->visible(fn (Get $get) => (bool) $get('custom_permissions')),
                ])
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),

            // Branch, clock-in and salary. Only who can open the salaries page sees or edits it;
            // hidden fields aren't saved, so nobody else can change these values.
            Forms\Components\Section::make('الفرع والحضور والمرتب')
                ->description('الموظف بيبصم من مكان الفرع بتاعه (Latitude / Longitude الفرع من صفحة الفروع).')
                ->visible(fn () => static::canManagePay())
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('الفرع')
                        ->relationship('branch', 'name')
                        ->helperText(fn (Get $get) => ($branch = \App\Models\Branch::find($get('branch_id'))) && ($branch->latitude === null || $branch->longitude === null)
                            ? '⚠️ الفرع ده لسه ملوش Latitude / Longitude - حددهم من صفحة الفروع عشان البصمة تشتغل.'
                            : null)
                        ->searchable()
                        ->preload()
                        ->live()
                        ->nullable()
                        ->required(fn (Get $get) => (bool) $get('is_showroom_employee')),
                    Forms\Components\Select::make('warehouse_id')
                        ->label('المعرض / الخزنة')
                        ->helperText('اللي بيشوف خزنته من غير صلاحية كاملة.')
                        ->relationship('warehouse', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Toggle::make('is_showroom_employee')
                        ->label('بيبصم وبيتحسب له مرتب')
                        ->helperText('فعّلها لو الموظف بيسجل حضور وانصراف وبيتحسب له مرتب ونقط.')
                        ->live(),
                    Forms\Components\TextInput::make('monthly_salary')
                        ->label('المرتب الشهري')
                        ->numeric()
                        ->prefix('ج')
                        ->minValue(0)
                        ->visible(fn (Get $get) => (bool) $get('is_showroom_employee'))
                        ->required(fn (Get $get) => (bool) $get('is_showroom_employee')),
                    Forms\Components\TimePicker::make('attendance_time')
                        ->label('ميعاد الحضور')
                        ->helperText('في المعاد أو قبله = نقطة خضرا، بعده = حمرا، 3 ساعات أو أكتر = سودا.')
                        ->seconds(false)
                        ->visible(fn (Get $get) => (bool) $get('is_showroom_employee'))
                        ->required(fn (Get $get) => (bool) $get('is_showroom_employee')),
                    Forms\Components\TimePicker::make('departure_time')
                        ->label('ميعاد الانصراف')
                        ->seconds(false)
                        ->visible(fn (Get $get) => (bool) $get('is_showroom_employee'))
                        ->required(fn (Get $get) => (bool) $get('is_showroom_employee')),
                ])
                ->columns(3)
                ->columnSpanFull(),
          ]);
    }

    /** Branch, salary and clock-in times follow the salaries page: whoever may open it may set them. */
    protected static function canManagePay(): bool
    {
        return \App\Support\Access::allows(\App\Filament\Pages\Salaries::class, 'view');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني'),

                Tables\Columns\IconColumn::make('is_admin')
                    ->boolean()
                    ->label('أدمن'),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->boolean()
                    ->label('سوبر أدمن'),
Tables\Columns\IconColumn::make('is_hitler')
    ->boolean()
    ->label('هتلر')
    ->visible(fn () => auth()->user()?->is_super_admin),
               Tables\Columns\IconColumn::make('is_bot')
    ->boolean()
    ->label('بوت')
    ->visible(fn () => auth()->user()?->is_super_admin),
Tables\Columns\IconColumn::make('is_company_employee')
    ->boolean()
    ->label('موظف شركة'),

Tables\Columns\TextColumn::make('branch.name')
    ->label('الفرع')
    ->placeholder('—')
    ->toggleable(),
Tables\Columns\TextColumn::make('installmentSystems.name')
    ->label('الشركات')
    ->badge()
    ->separator(',')
    ->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y'),
            ])
            ->defaultSort('created_at', 'desc')
     ->actions([
    Tables\Actions\EditAction::make(),

    Tables\Actions\DeleteAction::make()
        ->before(function (Staff $record, Tables\Actions\DeleteAction $action) {

            if ($record->is_hitler) {

                Notification::make()
                    ->danger()
                    ->title('')
                    ->body('إنت بتعمل إيه؟ ده هتلر!! يحقير يعويل يذليل بتحذف رئيسك ! .')
                    ->persistent()
                    ->send();

                $action->halt();
            }
        }),
])
->bulkActions([
    Tables\Actions\DeleteBulkAction::make()
        ->before(function ($records, Tables\Actions\DeleteBulkAction $action) {

            if ($records->contains(fn (Staff $record) => $record->is_hitler)) {

                Notification::make()
                    ->danger()
                    ->title('إيه يا عم؟ 😂')
                    ->body('اسعي جااهدا انك متتشمش بكل حبايبك')
                    ->persistent()
                    ->send();

                $action->halt();
            }
        }),
]);
    }
public static function mutateFormDataBeforeSave(
    array $data,
    ?Staff $record = null
): array {
    $user = auth()->user();

    /*
     * أي شخص غير السوبر أدمن:
     * ممنوع يعدل صلاحيات الأدمن والشركات.
     */
    if (! $user?->is_super_admin) {
        unset(
            $data['is_admin'],
            $data['is_super_admin'],
            $data['is_company_employee'],
            $data['installmentSystems'],
        );
    }

    /*
     * أي شخص مش هتلر:
     * ممنوع يعدل هتلر أو البوت.
     */
    if (! $user?->is_hitler) {
        unset(
            $data['is_hitler'],
            $data['is_bot'],
        );
    }

    /*
     * منع السوبر أدمن من تعديل صلاحيات حسابه الشخصي
     * (مع بقاء هتلر والبوت حسب القاعدة السابقة).
     */
    if ($record && $record->id === $user?->id) {
        unset(
            $data['is_admin'],
            $data['is_super_admin'],
            $data['is_company_employee'],
            $data['installmentSystems'],
        );
    }

    return $data;
}
    /** Roles are set by whoever may open the roles tab, never on his own account. */
    public static function canManageAccess(?Staff $record = null): bool
    {
        return RoleResource::canViewAny() && $record?->id !== auth()->id();
    }

    /** Custom permissions are saved only while their toggle is on; off means the role decides. */
    public static function prepareAccessData(array $data, ?Staff $record = null): array
    {
        if (! static::canManageAccess($record)) {
            unset($data['role_id'], $data['permissions'], $data['custom_permissions']);

            return $data;
        }

        $data['permissions'] = ($data['custom_permissions'] ?? false)
            ? \App\Filament\Support\PermissionMatrix::clean($data['permissions'] ?? [])
            : null;
        unset($data['custom_permissions']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return $user->is_admin || $user->is_super_admin || (bool) $user->is_hitler;
    }
}

