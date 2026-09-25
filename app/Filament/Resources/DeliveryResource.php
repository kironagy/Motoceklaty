<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryResource\Pages;
use Filament\Forms\Get;
use App\Models\InstallmentRequest;
use App\Models\InstallmentSystem;
use Carbon\Carbon;
use App\Services\PushNotificationService;
use App\Models\Machine;
use App\Models\Brand;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

use Filament\Notifications\Notification;
class DeliveryResource extends Resource
{
    protected static ?string $model = InstallmentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $pluralLabel = 'تسليم الطلبات';
    protected static ?string $navigationLabel = 'تسليم الطلبات';


    protected static function isLocked(?InstallmentRequest $record): bool
    {
        if (!$record)
            return false;

        $user = auth()->user();

        // admin و super_admin دايمًا يقدروا يعدلوا
        if (
            $user && (
                ($user->is_admin ?? false) ||
                ($user->is_super_admin ?? false) ||
                in_array($user->role ?? null, ['admin', 'super_admin'])
            )
        ) {
            return false;
        }

        // ✅ لو التيم ليدر فتحه للموظف
        if ((bool) $record->employee_editable === true) {
            return false;
        }

        if ($record->status !== 'paused')
            return false;

        $baseTime = $record->status_updated_at ?? $record->updated_at ?? $record->created_at;

        return Carbon::parse($baseTime)->addHours(48)->isPast();
    }
    protected static function isSuperAdmin(): bool
    {
        $user = Auth::user();
        // عدّلها حسب عندك: is_super_admin أو role أو أي نظام صلاحيات
        return (bool) ($user->is_super_admin ?? false);
    }

    protected static function isAdminOrSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->is_admin ||
            $user->is_super_admin ||
            in_array($user->role ?? null, ['admin', 'super_admin'])
        );
    }
    public static function canEdit($record): bool
    {
        if (static::isAdminOrSuperAdmin()) {
            return true;
        }

        return !static::isLocked($record);
    }
public static function canDelete($record): bool
{
    $user = Auth::user();

    if ($user?->is_hitler) {
        return true;
    }

    if (static::isSuperAdmin()) {
        return true;
    }

    return !static::isLocked($record);
}
protected static function isHitler(): bool
{
    return (bool) (Auth::user()?->is_hitler ?? false);
}

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        /*
        |--------------------------------------------------------------------------
        | موظف شركة - أعلى أولوية حتى لو Admin / Super Admin
        |--------------------------------------------------------------------------
        */
        if ($user->is_company_employee ?? false) {

            $companyNames = $user->installmentSystems()
                ->pluck('installment_systems.name')
                ->toArray();

            // موظف شركة بدون شركات محددة = ممنوع يشوف طلبات
            if (empty($companyNames)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn(
                'installment_type',
                $companyNames
            );
        }

        // الأدمن والسوبر أدمن العادي
        if (
            ($user->is_admin ?? false) ||
            ($user->is_super_admin ?? false)
        ) {
            return $query;
        }

        // الموظف العادي
        return $query->where('staff_id', $user->id);
    }
    // ✅ تحميل بيانات النظام تلقائيًا عند التعديل
    protected static function loadSystemData($state, callable $set): void
    {
        $system = InstallmentSystem::where('name', $state)->first();

        if ($system && $system->plans) {
            $plans = is_string($system->plans)
                ? json_decode($system->plans, true)
                : (is_array($system->plans) ? $system->plans : []);

            $options = [];
            foreach ($plans as $plan) {
                $months = $plan['months'] ?? null;
                $interest = $plan['interest'] ?? null;

                if ($months && $interest !== null) {
                    $options[$months] = "{$months} شهر ({$interest}%)";
                }
            }

            $set('months_options', $options);
            $set('administrative_fees', $system->administrative_fees ?? 0);
        } else {
            $set('months_options', []);
            $set('administrative_fees', 0);
        }
    }
    protected static function toEnglishDigits(?string $value): ?string
    {
        if ($value === null)
            return null;

        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $easternArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($arabicIndic, $western, str_replace($easternArabicIndic, $western, $value));
    }

    protected static function normalizeEgyptianPhone(?string $value): ?string
    {
        $value = self::toEnglishDigits($value);

        if ($value === null || trim($value) === '') {
            return null;
        }

        // بعض البيانات القديمة فيها رقمين مفصولين بـ / أو , — ناخد الأول.
        $parts = preg_split('#[/,،|]+#u', $value);
        $value = $parts[0] ?? $value;

        $digits = preg_replace('/\D+/', '', $value ?? '');

        if ($digits === '') {
            return null;
        }

        // شيل كود الدولة (00201... / 201...) ورجّع الرقم لصيغة 01xxxxxxxxx
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) > 11 && str_starts_with($digits, '20')) {
            $digits = '0' . substr($digits, 2);
        }

        return substr($digits, 0, 11);
    }

    protected static function normalizeNationalId(?string $value): ?string
    {
        $value = self::toEnglishDigits($value);

        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : substr($digits, 0, 14);
    }

    public static function normalizePhoneForForm(?string $value): ?string
    {
        return self::normalizeEgyptianPhone($value);
    }

    public static function normalizeNationalIdForForm(?string $value): ?string
    {
        return self::normalizeNationalId($value);
    }

    public static function extractSecondaryPhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = preg_split('#[/,،|]+#u', self::toEnglishDigits($value));

        return isset($parts[1])
            ? self::normalizeEgyptianPhone($parts[1])
            : null;
    }

    /**
     * قاعدة تفرد لا تشتغل إلا لو القيمة اتغيّرت فعلاً عن المحفوظة،
     * عشان تعديل حالة الطلب ما يتعطّلش بسبب داتا قديمة مكررة.
     */
   protected static function uniqueIfChangedRule(
    string $column,
    string $message
): \Closure {
    return function (
        ?\Illuminate\Database\Eloquent\Model $record,
        \Filament\Forms\Get $get
    ) use ($column, $message) {

        return function (
            string $attribute,
            $value,
            \Closure $fail
        ) use (
            $record,
            $get,
            $column,
            $message
        ) {

            // القيمة فاضية → مفيش validation
            if ($value === null || $value === '') {
                return;
            }

            // لو بنعدل والقيمة هي نفسها القيمة القديمة
            if (
                $record &&
                (string) $record->{$column} === (string) $value
            ) {
                return;
            }

            /*
             |--------------------------------------------------------------------------
             | تحديد نوع الطلب من حالة الفورم نفسها
             |--------------------------------------------------------------------------
             |
             | مهم جدًا:
             | ممنوع نعتمد على request()->query('request_type')
             | لأن Livewire بيعمل requests جديدة أثناء الكتابة.
             |
             */

            $requestType = $get('request_type');

            $requestType = in_array($requestType, ['fake', 'bot'], true)
                ? $requestType
                : 'normal';

            /*
             |--------------------------------------------------------------------------
             | البحث عن التكرار داخل نفس نوع الطلب
             |--------------------------------------------------------------------------
             */

            $exists = InstallmentRequest::query()
                ->where($column, $value)
                ->where('request_type', $requestType)
                ->when(
                    $record,
                    fn ($query) =>
                        $query->whereKeyNot($record->getKey())
                )
                ->exists();

            if ($exists) {
                $fail($message);
            }
        };
    };
}
    protected static function normalizeApplicantName(?string $value): ?string
    {
        if ($value === null)
            return null;

        // يمنع أ/إ/آ بتحويلهم لـ ا
        $value = str_replace(['أ', 'إ', 'آ'], 'ا', $value);

        return $value;
    }


/**
 * لون الـ Section حسب اكتمال البيانات
 */
protected static function sectionStatusClass(bool $complete): string
{
    return $complete
        ? 'ring-1 ring-success-500 border-success-500'
        : 'ring-1 ring-danger-500 border-danger-500';
}

/**
 * التأكد أن القيمة موجودة
 */
protected static function filledValue($value): bool
{
    if (is_array($value)) {
        return !empty($value);
    }

    return filled($value);
}




/**
 * تجميع العنوان السكني في عنوان واحد منطقي
 */
protected static function buildFullAddress(Get $get): string
{
    $building    = trim((string) $get('applicant_building_number'));
    $street      = trim((string) $get('applicant_street'));
    $branch      = trim((string) $get('applicant_branch_street'));
    $governorate = trim((string) $get('applicant_governorate'));
    $area        = trim((string) $get('applicant_area'));
    $landmark    = trim((string) $get('applicant_landmark'));
    $floor       = trim((string) $get('applicant_floor'));
    $apartment   = trim((string) $get('applicant_apartment'));

    $parts = [];

    if ($building !== '') {
        $parts[] = "رقم العقار {$building}";
    }

    if ($street !== '') {
        $parts[] = "شارع {$street}";
    }

    if ($branch !== '') {
        $parts[] = "متفرع من {$branch}";
    }

    if ($governorate !== '') {
        $parts[] = "محافظة {$governorate}";
    }

    if ($area !== '') {
        $parts[] = "المنطقة {$area}";
    }

    if ($landmark !== '') {
        $parts[] = "علامة مميزة: {$landmark}";
    }

    if ($floor !== '') {
        $parts[] = "الدور {$floor}";
    }

    if ($apartment !== '') {
        $parts[] = "شقة {$apartment}";
    }

    return implode('، ', $parts);
}


/**
 * تجميع عنوان العمل في عنوان واحد منطقي
 */
protected static function buildWorkAddress(Get $get): string
{
    $building    = trim((string) $get('work_building_number'));
    $street      = trim((string) $get('work_street'));
    $branch      = trim((string) $get('work_branch_street'));
    $governorate = trim((string) $get('work_governorate'));
    $area        = trim((string) $get('work_area'));
    $landmark    = trim((string) $get('work_landmark'));
    $floor       = trim((string) $get('work_floor'));
    $apartment   = trim((string) $get('work_apartment'));

    $parts = [];

    if ($building !== '') {
        $parts[] = "رقم العقار {$building}";
    }

    if ($street !== '') {
        $parts[] = "شارع {$street}";
    }

    if ($branch !== '') {
        $parts[] = "متفرع من {$branch}";
    }

    if ($governorate !== '') {
        $parts[] = "محافظة {$governorate}";
    }

    if ($area !== '') {
        $parts[] = "المنطقة {$area}";
    }

    if ($landmark !== '') {
        $parts[] = "علامة مميزة: {$landmark}";
    }

    if ($floor !== '') {
        $parts[] = "الدور {$floor}";
    }

    if ($apartment !== '') {
        $parts[] = "شقة {$apartment}";
    }

    return implode('، ', $parts);
}




public static function buildFullAddressFromData(array $data): string{
    $parts = [];

    $building = trim((string) ($data['applicant_building_number'] ?? ''));
    $street = trim((string) ($data['applicant_street'] ?? ''));
    $branch = trim((string) ($data['applicant_branch_street'] ?? ''));
    $governorate = trim((string) ($data['applicant_governorate'] ?? ''));
    $area = trim((string) ($data['applicant_area'] ?? ''));
    $landmark = trim((string) ($data['applicant_landmark'] ?? ''));
    $floor = trim((string) ($data['applicant_floor'] ?? ''));
    $apartment = trim((string) ($data['applicant_apartment'] ?? ''));

    if ($building !== '') {
        $parts[] = "رقم العقار {$building}";
    }

    if ($street !== '') {
        $parts[] = "شارع {$street}";
    }

    if ($branch !== '') {
        $parts[] = "متفرع من {$branch}";
    }

    if ($governorate !== '') {
        $parts[] = "محافظة {$governorate}";
    }

    if ($area !== '') {
        $parts[] = "المنطقة {$area}";
    }

    if ($landmark !== '') {
        $parts[] = "علامة مميزة: {$landmark}";
    }

    if ($floor !== '') {
        $parts[] = "الدور {$floor}";
    }

    if ($apartment !== '') {
        $parts[] = "شقة {$apartment}";
    }

    return implode('، ', $parts);
}


public static function buildWorkAddressFromData(array $data): string
{
    $parts = [];

    $building = trim((string) ($data['work_building_number'] ?? ''));
    $street = trim((string) ($data['work_street'] ?? ''));
    $branch = trim((string) ($data['work_branch_street'] ?? ''));
    $governorate = trim((string) ($data['work_governorate'] ?? ''));
    $area = trim((string) ($data['work_area'] ?? ''));
    $landmark = trim((string) ($data['work_landmark'] ?? ''));
    $floor = trim((string) ($data['work_floor'] ?? ''));
    $apartment = trim((string) ($data['work_apartment'] ?? ''));

    if ($building !== '') {
        $parts[] = "رقم العقار {$building}";
    }

    if ($street !== '') {
        $parts[] = "شارع {$street}";
    }

    if ($branch !== '') {
        $parts[] = "متفرع من {$branch}";
    }

    if ($governorate !== '') {
        $parts[] = "محافظة {$governorate}";
    }

    if ($area !== '') {
        $parts[] = "المنطقة {$area}";
    }

    if ($landmark !== '') {
        $parts[] = "علامة مميزة: {$landmark}";
    }

    if ($floor !== '') {
        $parts[] = "الدور {$floor}";
    }

    if ($apartment !== '') {
        $parts[] = "شقة {$apartment}";
    }

    return implode('، ', $parts);
}




























    // ✅ نموذج الفورم الكامل
    public static function form(Form $form): Form
    {
        $isCreate = $form->getOperation() === 'create';
$requestType = request()->query('request_type') === 'fake'
    ? 'fake'
    : 'normal';

        return $form->schema([
     Forms\Components\Hidden::make('request_type')
            ->default($requestType)
            ->dehydrated(true),
            // 🔹 بيانات النظام والمكنة
            // 🔹 بيانات النظام والمكنة
           Forms\Components\Section::make('بيانات النظام والمكنة')
    ->icon('heroicon-o-cog-6-tooth')
    ->collapsible()
    ->collapsed(false)
    ->extraAttributes(function (Get $get) {

        $complete =
            filled($get('installment_type')) &&
            filled($get('months')) &&
            filled($get('staff_id')) &&
            filled($get('brand_id')) &&
            filled($get('machine_id')) &&
            filled($get('machine_installment_price'));

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema(function () {
                    return [

                        // 🟢 نوع النظام
                        Forms\Components\Select::make('installment_type')
                            ->label('نوع النظام')
                            ->options(function () {
                                $user = Auth::user();

                                if (
                                    $user &&
                                    ($user->is_company_employee ?? false) &&
                                    $user->installment_system_id
                                ) {
                                    return InstallmentSystem::query()
                                        ->whereKey($user->installment_system_id)
                                        ->pluck('name', 'name')
                                        ->toArray();
                                }

                                return InstallmentSystem::query()
                                    ->pluck('name', 'name')
                                    ->toArray();
                            })
                            ->default(function () {
                                $user = Auth::user();

                                if (
                                    $user &&
                                    ($user->is_company_employee ?? false) &&
                                    $user->installment_system_id
                                ) {
                                    return InstallmentSystem::whereKey(
                                        $user->installment_system_id
                                    )->value('name');
                                }

                                return null;
                            })
                            ->disabled(
                                fn() =>
                                (bool) (Auth::user()?->is_company_employee ?? false)
                            )
                            ->dehydrated(true)
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(
                                fn($state, callable $set) =>
                                self::loadSystemData($state, $set)
                            )
                            ->afterStateHydrated(
                                fn($state, callable $set) =>
                                self::loadSystemData($state, $set)
                            )
                            ->required(),

                        // 🟢 المصاريف الإدارية
                        Forms\Components\TextInput::make('administrative_fees')
                            ->label('المصاريف الإدارية')
                            ->suffix('%')
                            ->disabled()
                            ->dehydrated(true),

                        // 🟢 عدد الشهور
                        Forms\Components\Select::make('months')
                            ->label('عدد الشهور')
                            ->options(fn(callable $get) => $get('months_options') ?? [])
                            ->reactive()
                            ->required(),

                        // 🧑‍💼 اسم الموظف
                        // 🧑‍💼 اختيار الموظف (يظهر فقط للأدمن، ويكون قراءة فقط للموظف العادي)
                        Forms\Components\Select::make('staff_id')
                            ->label('اسم الموظف')
                            ->options(Staff::pluck('name', 'id'))
                            ->searchable()
                            ->disabled(fn() => !Auth::user()->is_admin && !Auth::user()->is_super_admin)
                            ->dehydrated(true)
                            ->default(fn($record) => $record?->staff_id ?? Auth::id())
                            ->required(),


                        // 🟣 نوع المكنة (دائمًا ظاهر وقابل للتعديل)
                        Forms\Components\Select::make('brand_id')
                            ->label('نوع المكنة')
                            ->options(Brand::pluck('name', 'id'))
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(fn($state, callable $set) => $set('machine_id', null))
                            ->afterStateHydrated(function ($state, callable $set, $record) {
                                if ($record && $record->machine && $record->machine->brand_id) {
                                    $set('brand_id', $record->machine->brand_id);
                                }
                            })
                            ->required(),

                        // 🟣 اسم المكنة (دائمًا ظاهر وقابل للتعديل)
                        Forms\Components\Select::make('machine_id')
                            ->label('اسم المكنة')
                            ->options(function (callable $get) {
                                $brandId = $get('brand_id');
                                if (!$brandId)
                                    return [];
                                return Machine::where('brand_id', $brandId)->pluck('name', 'id');
                            })
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $machine = \App\Models\Machine::find($state);
                                $set('machine_installment_price', $machine?->installment_price ?? null);
                                $set('machine_cash_price', $machine?->cash_price ?? null);
                            })
                            ->afterStateHydrated(function ($state, callable $set, $record) {
                                if ($record && $record->machine_id) {
                                    $set('machine_id', $record->machine_id);
                                }
                            })
                            ->required(),

                        // 💰 سعر المكنة بالتقسيط
                        Forms\Components\TextInput::make('machine_installment_price')
                            ->label('سعر المكنة بالتقسيط')
                            ->prefix('ج.م')
                            ->numeric()
                            ->dehydrated(true)
                            ->reactive()
                            ->minValue(function (callable $get, $record) {
                                $machineId = $get('machine_id') ?? $record?->machine_id;

                                return (float) (
                                    \App\Models\Machine::find($machineId)?->installment_price ?? 0
                                );
                            })
                            ->rules([
                                function (callable $get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $machineId = $get('machine_id');
                                        $machine = \App\Models\Machine::find($machineId);

                                        if (!$machine) {
                                            return;
                                        }

                                        $basePrice = (float) $machine->installment_price;

                                        if ((float) $value < $basePrice) {
                                            $fail("مينفعش السعر يقل عن السعر الأساسي للمكنة المختارة: {$basePrice} ج.م");
                                        }
                                    };
                                },
                            ])
                            ->afterStateHydrated(function ($state, callable $set, $record) {
                                if ($record) {
                                    $set(
                                        'machine_installment_price',
                                        $record->machine_installment_price
                                        ?? $record->machine?->installment_price
                                    );
                                }
                            }),
                        Forms\Components\TextInput::make('deposit')
                            ->label('االمقدم بدون المصاريف الادارية')
                            ->prefix('ج.م')
                            ->numeric()
                            ->default(0)
                            ->dehydrated(true),
                    ];
                })
                // 💵 المقدم


                ->columns(4),




            // 🔹 عرض السعر (خاص بنظام أمان)
            // 🔹 عرض السعر (خاص بنظام أمان)
            // 🔹 عرض السعر (خاص بنظام أمان)
          Forms\Components\Section::make('عرض السعر')
    ->icon('heroicon-o-document-text')
    ->collapsible()
    ->collapsed()

    ->extraAttributes(function (Get $get) {

        $installmentType = $get('installment_type');

        // لو النظام مش محتاج عرض سعر
        $needsPriceOffer = in_array($installmentType, [
            'امان',
            'امان (بدون مصاريف ادارية)',
            'امان زيرو مصاريف',
            'امان بدون مصاريف',
            'امان - الجيزة',
            'امان - القاهرة'
        ]);

        if (!$needsPriceOffer) {
            return [
                'class' => 'ring-1 ring-success-500 border-success-500',
            ];
        }

        $complete = filled($get('price_offer_image'));

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema([

                    Forms\Components\FileUpload::make('price_offer_image')
                        ->label('صورة عرض السعر')
                        ->directory('installments/price_offers')
                        ->visibility('public')
                        ->image()
        ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(fn($file) => $file->getClientOriginalName())
                        ->dehydrated(true)
                        ->required(false),

                    Forms\Components\View::make('filament.custom.download')
                        ->dehydrated(false)  // ✔ أهم خطوة
                        ->viewData([
                            'url' => fn($record) =>
                                $record?->price_offer_image
                                ? asset('storage/' . $record->price_offer_image)
                                : null,
                            'label' => 'تحميل عرض السعر الحالي',
                        ]),

                ])
                ->visible(fn($get) => in_array($get('installment_type'), [
                    'امان',
                    'امان (بدون مصاريف ادارية)',
                    'امان زيرو مصاريف',
                    'امان بدون مصاريف',
                    'امان - الجيزة',
                    'امان - القاهرة'
                ]))
                ->columns(1),



Forms\Components\Section::make('بيانات العميل')
    ->icon('heroicon-o-user')
    ->collapsible()
    ->collapsed()
    ->extraAttributes(function (Get $get) {

$complete =
    filled($get('applicant_name')) &&
    filled($get('applicant_phone')) &&
    filled($get('applicant_national_id')) &&
    filled($get('applicant_id_image')) &&
    filled($get('applicant_id_back_image'));

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema([

                    Forms\Components\TextInput::make('applicant_name')
                        ->label('اسم العميل')
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('applicant_name', self::normalizeApplicantName($state));
                        })
                        ->dehydrateStateUsing(fn($state) => self::normalizeApplicantName($state))
                        // تأكيد بالـ validation (لو حد لصق نص قبل التحويل)
                        ->rule('not_regex:/[أإآ]/u')
                        ->validationMessages([
                            'not_regex' => 'اسم العميل لا يجب أن يحتوي على (أ، إ، آ).',
                        ]),


                    Forms\Components\TextInput::make('applicant_phone')
                        ->label('رقم الهاتف')
                        ->required()
                        ->reactive()
                        ->afterStateHydrated(function ($state, callable $set) {
                            $set('applicant_phone', self::normalizeEgyptianPhone($state));
                        })
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('applicant_phone', self::normalizeEgyptianPhone($state));
                        })
                        ->dehydrateStateUsing(fn($state) => self::normalizeEgyptianPhone($state))
                        ->maxLength(11)
                        ->minLength(11)
                        ->rule('regex:/^\d{11}$/')
                        ->rule(self::uniqueIfChangedRule('applicant_phone', 'رقم الهاتف دا متسجل قبل كدا.'))
                        ->validationMessages([
                            'regex' => 'رقم الهاتف يجب أن يكون 11 رقمًا بالضبط.',
                        ]),

                    Forms\Components\TextInput::make('applicant_phone_2')
                        ->label('رقم الهاتف الثاني')
                        ->nullable()
                        ->reactive()
                        ->afterStateHydrated(function ($state, callable $set) {
                            $set('applicant_phone_2', self::normalizeEgyptianPhone($state));
                        })
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('applicant_phone_2', self::normalizeEgyptianPhone($state));
                        })
                        ->dehydrateStateUsing(fn($state) => self::normalizeEgyptianPhone($state))
                        ->maxLength(11)
                        ->rule('regex:/^\d{11}$/')
                        ->rule(self::uniqueIfChangedRule('applicant_phone_2', 'رقم الهاتف الثاني دا متسجل قبل كدا.'))
                        ->validationMessages([
                            'regex' => 'رقم الهاتف الثاني يجب أن يكون 11 رقمًا بالضبط.',
                        ]),
   // ================================
                    // صورة البطاقة (الظهر)
                    // ================================
/***Forms\Components\Textarea::make('applicant_address')
    ->label('العنوان')
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $set('applicant_address', self::toEnglishDigits($state));
    })
    ->disabled()
    ->dehydrateStateUsing(fn($state) => self::toEnglishDigits($state)),
***/
                    Forms\Components\TextInput::make('applicant_national_id')
                        ->label('الرقم القومي')
                        ->default(fn() => request()->query('nid'))   // ✅ يتعبّى تلقائيًا من المودال
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('applicant_national_id', self::normalizeNationalId($state));
                        })
                        ->dehydrateStateUsing(fn($state) => self::normalizeNationalId($state))
                        ->minLength(14)
                        ->maxLength(14)
                        ->rule('regex:/^\d{14}$/')
                        ->rule(self::uniqueIfChangedRule('applicant_national_id', 'الرقم القومي دا متسجل قبل كدا.'))
                        ->validationMessages([
                            'regex' => 'الرقم القومي يجب أن يكون 14 رقمًا بالضبط.',
                        ]),

                    Forms\Components\Toggle::make('applicant_age_ok')
                        ->label('مستوفي شرط السن'),

                    Forms\Components\Toggle::make('employee_editable')
                        ->label('مسموح للموظف بالتعديل')
                        ->visible(fn() => static::isAdminOrSuperAdmin())
                        ->helperText('لو مفعل، الموظف يقدر يعدل حتى لو عدى 48 ساعة')
                        ->default(false),
                    // ================================
                    // صورة البطاقة (الوجه)
                    // ================================
                    Forms\Components\Group::make([
                        // عرض الصورة القديمة
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(fn($record) => $record && $record->applicant_id_image)

                            ->viewData([
                                'url' => fn($record) => $record?->applicant_id_image
                                    ? asset('storage/' . $record->applicant_id_image)
                                    : null,
                                'label' => 'تحميل الوجه الحالي',
                            ]),

                        // تعديل / رفع صورة جديدة
                        Forms\Components\FileUpload::make('applicant_id_image')
                            ->label('صورة البطاقة (الوجه)')
                                ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
    ->required()
                            ->directory('installments/applicants')
                            ->visibility('public')
                            ->image()
                            ->nullable(),
                    ]),


                    // ================================
                    // صورة البطاقة (الظهر)
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(fn($record) => $record && $record->applicant_id_back_image)
 
                            ->viewData([
                                'url' => fn($record) => $record?->applicant_id_back_image
                                    ? asset('storage/' . $record->applicant_id_back_image)
                                    : null,
                                'label' => 'تحميل الظهر الحالي',
                            ]),

                        Forms\Components\FileUpload::make('applicant_id_back_image')
                            ->label('صورة البطاقة (الظهر)')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
    ->required()
                            ->directory('installments/applicants')
                            ->visibility('public')
                            ->image()
                            ->nullable(),
                    ]),


                    // ================================
                    // صورة كارت الميديكال — لعبد اللطيف جميل
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(
                                fn($record) =>
                                $record &&
                                $record->medical_card_image &&
                                $record->installment_type === 'عبد اللطيف جميل'
                            )
                            ->viewData([
                                'url' => fn($record) => $record?->medical_card_image
                                    ? asset('storage/' . $record->medical_card_image)
                                    : null,
                                'label' => 'تحميل كارت الميديكال',
                            ]),

                        Forms\Components\FileUpload::make('medical_card_image')
                            ->label('صورة كارت الميديكال')
                            ->directory('installments/medical_cards')
                            ->visibility('public')
                            ->image()
                            ->nullable()
                            ->visible(fn($get) => $get('installment_type') === 'عبد اللطيف جميل'),
                    ]),

             
               
// =====================================================
// 🔹 العنوان السكني
// =====================================================

Forms\Components\Placeholder::make('residential_address_separator')
    ->label('العنوان السكني')
    ->content('بيانات عنوان سكن العميل')
    ->columnSpanFull(),

Forms\Components\TextInput::make('applicant_building_number')
    ->label('رقم العقار')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_street')
    ->label('اسم الشارع')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_branch_street')
    ->label('اسم الشارع المتفرع منه')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_governorate')
    ->label('اسم المحافظة')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_area')
    ->label('المنطقة')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_landmark')
    ->label('العلامة المميزة')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_floor')
    ->label('رقم الدور')
    ->required(fn () => $isCreate),

Forms\Components\TextInput::make('applicant_apartment')
    ->label('رقم الشقة')
    ->required(fn () => $isCreate),

Forms\Components\Textarea::make('applicant_address')
    ->label('العنوان الكامل')
    ->rows(4)
    ->columnSpanFull()
    ->readOnly()
    ->dehydrated(true)
    ->afterStateHydrated(function (callable $set, Get $get) {
        $set(
            'applicant_address',
            self::buildFullAddress($get)
        );
    })
    ->extraAttributes([
        'class' => 'cursor-pointer select-all',
        'x-on:click' => '$el.select()',
    ])
    ->helperText(
        'العنوان يتم تجميعه تلقائيًا — اضغط داخل الخانة لتحديده ونسخه.'
    ),

])
->columns(3),



    
            // 🔹 بيانات الضامن
 
            // 🔹 الحالة الوظيفية
        Forms\Components\Section::make('الحالة الوظيفية')
    ->icon('heroicon-o-briefcase')
    ->collapsible()
    ->collapsed()
    ->extraAttributes(function (Get $get) {

        $workStatus = $get('work_status');

        $complete = filled($workStatus);

        if ($workStatus === 'employee') {

            $complete =
                filled($get('work_address')) &&
                filled($get('salary_slip_file'));

        } elseif ($workStatus === 'self_employed') {

            $complete =
                filled($get('work_address')) &&
                filled($get('commercial_reg_file')) &&
                filled($get('tax_card_file')) &&
                filled($get('place_video'));

        } elseif ($workStatus === 'pension') {

            $complete =
                filled($get('pension_statement_file'));

        } elseif ($workStatus === 'no_income_proof') {

            $complete =
                filled($get('free_work_name')) &&
                filled($get('free_work_address')) &&
                filled($get('free_income_proof_images'));

        } else {

            $complete = false;
        }

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema([

                    // الحالة الوظيفية
                    Forms\Components\Select::make('work_status')
                        ->label('الحالة الوظيفية')
                        ->options([
                            'employee' => 'موظف',
                            'pension' => 'صاحب معاش',
                            'self_employed' => 'صاحب نشاط',
                            'no_income_proof' => 'دخل حر',
                        ])
                        ->reactive(),
// =====================================================
// 🔹 عنوان العمل
// =====================================================



Forms\Components\Section::make('عنوان العمل')
    ->icon('heroicon-o-building-office-2')
    ->collapsible()
    ->collapsed()
    ->visible(fn($get) => in_array($get('work_status'), [
        'employee',
        'self_employed',
        'no_income_proof',
    ]))
    ->extraAttributes(function (Get $get) {

        $complete =
            filled($get('work_building_number')) &&
            filled($get('work_street')) &&
            filled($get('work_branch_street')) &&
            filled($get('work_governorate')) &&
            filled($get('work_area')) &&
            filled($get('work_landmark')) &&
            filled($get('work_floor')) &&
            filled($get('work_apartment'));

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema([

Forms\Components\TextInput::make('work_building_number')
    ->label('رقم العقار')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_street')
    ->label('اسم الشارع')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_branch_street')
    ->label('اسم الشارع المتفرع منه')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_governorate')
    ->label('اسم المحافظة')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_area')
    ->label('المنطقة')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_landmark')
    ->label('العلامة المميزة')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_floor')
    ->label('رقم الدور')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),

Forms\Components\TextInput::make('work_apartment')
    ->label('رقم الشقة')
    ->required(fn (Get $get) => $isCreate && in_array($get('work_status'), [
        'employee',
        'self_employed',
    ])),
        // =====================================================
        // 🔥 العنوان الكامل للعمل
        // =====================================================

        Forms\Components\Textarea::make('work_address')
            ->label('العنوان الكامل للعمل')
            ->rows(4)
            ->columnSpanFull()
            ->readOnly()
            ->dehydrated(true)
            ->afterStateHydrated(function (callable $set, Get $get) {
                $set(
                    'work_address',
                    self::buildWorkAddress($get)
                );
            })
            ->extraAttributes([
                'class' => 'cursor-pointer select-all',
                'x-on:click' => '$el.select()',
            ])
            ->helperText(
                'العنوان يتم تجميعه تلقائيًا — اضغط داخل الخانة لتحديده ونسخه.'
            ),

    ])
    ->columns(3),
                    Forms\Components\TextInput::make('free_work_name')
                        ->label('اسم العمل')
                        ->required()
                        ->visible(fn($get) => $get('work_status') === 'no_income_proof'),

                    Forms\Components\Textarea::make('free_work_address')
                        ->label('عنوان مكان العمل')
                        ->rows(2)
                        ->columnSpan('full')
                        ->disabled()
                        ->visible(fn($get) => $get('work_status') === 'no_income_proof'),


                    // عنوان العمل بيتجمع تلقائي في قسم "عنوان العمل" - نسخة تانية
                    // من نفس الحقل هنا كانت بتكتب على نفس القيمة.


                    // ================================
// صور إثبات الدخل الحر (دخل حر)
// ================================
                    Forms\Components\Group::make([
                        // عرض الصور القديمة بشكل كبير + زر تحميل
                        Forms\Components\View::make('filament.custom.free-income-download-grid')
                            ->dehydrated(false)

                            ->visible(
                                fn($record, $get) =>
                                $get('work_status') === 'no_income_proof'
                                && $record
                                && !empty($record->free_income_proof_images)
                            )
                            ->viewData([
                                'files' => fn($record) => $record?->free_income_proof_images ?? [],
                                'label' => 'تحميل إثبات الدخل',
                            ])
                            ->columnSpan('full'),

                        // الرفع (هيفضل زي ما هو)
                        Forms\Components\FileUpload::make('free_income_proof_images')
                            ->label('صور إثبات (دخل حر)')
       ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                            ->disk('public')
                            ->directory('installments/free_income_proofs')
                            ->visibility('public')
                            ->multiple()
                            ->image()
                            ->imagePreviewHeight('120')
                            ->preserveFilenames()
                            ->getUploadedFileNameForStorageUsing(fn($file) => $file->getClientOriginalName())
                            ->nullable()
                            ->columnSpan('full')
                            ->visible(fn($get) => $get('work_status') === 'no_income_proof'),
                    ])
                        ->columnSpan('full'),


                    // ================================
                    // مفردات المرتب (موظف)
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(
                                fn($record) =>
                                $record &&
                                $record->salary_slip_file &&
                                $record->work_status === 'employee'
                            )
 
                            ->viewData([
                                'url' => fn($record) => asset('storage/' . $record->salary_slip_file),
                                'label' => 'تحميل مفردات المرتب',
                            ]),

                        Forms\Components\FileUpload::make('salary_slip_file')
                            ->label('مفردات المرتب')
                            ->directory('installments/salary_slips')
                            ->visibility('public')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                            ->image()
                            ->nullable()
                            ->visible(fn($get) => $get('work_status') === 'employee'),
                    ]),



                    // ================================
                    // السجل التجاري (صاحب نشاط)
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(
                                fn($record) =>
                                $record &&
                                $record->commercial_reg_file &&
                                $record->work_status === 'self_employed'
                            )

                            ->viewData([
                                'url' => fn($record) => asset('storage/' . $record->commercial_reg_file),
                                'label' => 'تحميل السجل التجاري',
                            ]),

                        Forms\Components\FileUpload::make('commercial_reg_file')
                            ->label('السجل التجاري')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                            ->directory('installments/business')
                            ->visibility('public')
                            ->image()
                            ->nullable()
                            ->visible(fn($get) => $get('work_status') === 'self_employed'),
                    ]),



                    // ================================
                    // البطاقة الضريبية (صاحب نشاط)
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(
                                fn($record) =>
                                $record &&
                                $record->tax_card_file &&
                                $record->work_status === 'self_employed'
                            )

                            ->viewData([
                                'url' => fn($record) => asset('storage/' . $record->tax_card_file),
                                'label' => 'تحميل البطاقة الضريبية',
                            ]),

                        Forms\Components\FileUpload::make('tax_card_file')
                            ->label('البطاقة الضريبية')
                            ->directory('installments/business')
                            ->visibility('public')
                            ->image()
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                            ->nullable()
                            ->visible(fn($get) => $get('work_status') === 'self_employed'),
                    ]),



                    // ================================
                    // فيديو/صور النشاط التجاري (multiple)
                    // ================================
                    Forms\Components\Group::make(function () {

                        return [
                            // عرض كل الصور + الفيديوهات
                            Forms\Components\View::make('filament.custom.multiple-preview')
                                ->visible(
                                    fn($record) =>
                                    $record &&
                                    $record->place_video &&
                                    $record->work_status === 'self_employed'
                                )
                                ->viewData([
                                    'files' => function ($record) {
                                        // لو مفيش record
                                        if (!$record) {
                                            return [];
                                        }

                                        // لو مفيش أي ملفات
                                        if (!$record->place_video) {
                                            return [];
                                        }

                                        // لو multiple
                                        if (is_array($record->place_video)) {
                                            return collect($record->place_video)
                                                ->map(fn($f) => asset('storage/' . $f))
                                                ->toArray();
                                        }

                                        // لو single string
                                        return [asset('storage/' . $record->place_video)];
                                    },

                                ]),

                            // رفع ملفات جديدة
                            Forms\Components\FileUpload::make('place_video')
                                ->label('فيديو أو صور النشاط التجاري')
                                ->directory('installments/business')
                                ->visibility('public')
                                ->multiple()
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
   
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'])
                                ->nullable()
                                ->columnSpan('full')
                                ->visible(fn($get) => $get('work_status') === 'self_employed'),
                        ];
                    }),



                    // ================================
                    // بيان المعاش (صاحب معاش)
                    // ================================
                    Forms\Components\Group::make([
                        Forms\Components\View::make('filament.custom.download')
                            ->visible(
                                fn($record) =>
                                $record &&
                                $record->pension_statement_file &&
                                $record->work_status === 'pension'
                            )

                            ->viewData([
                                'url' => fn($record) => asset('storage/' . $record->pension_statement_file),
                                'label' => 'تحميل بيان المعاش',
                            ]),

                        Forms\Components\FileUpload::make('pension_statement_file')
                            ->label('صورة بيان المعاش')
                            ->directory('installments/pension')
                            ->visibility('public')
                                ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                            ->image()
                            ->nullable()
                            ->visible(fn($get) => $get('work_status') === 'pension'),
                    ]),

                ])
                ->columns(2),
                          Forms\Components\Section::make('بيانات الضامن')
    ->icon('heroicon-o-user-group')
    ->collapsible()
    ->collapsed()
     ->visible(fn (Get $get) => $get('work_status') === 'pension')

    ->extraAttributes(function (Get $get) {

        $installmentType = $get('installment_type');

        // نظام حالا
        if ($installmentType === 'حالا') {

           $guarantors = $get('guarantors') ?? [];

// لو البيانات جاية String JSON من قاعدة البيانات
if (is_string($guarantors)) {
    $guarantors = json_decode($guarantors, true) ?? [];
}

// تأكيد إنها Array
if (!is_array($guarantors)) {
    $guarantors = [];
}

$complete = !empty($guarantors);

if ($complete) {
    foreach ($guarantors as $guarantor) {

        // حماية إضافية لو عنصر الضامن نفسه مش Array
        if (!is_array($guarantor)) {
            $complete = false;
            break;
        }

        if (
            blank($guarantor['name'] ?? null) ||
            blank($guarantor['phone'] ?? null) ||
            blank($guarantor['address'] ?? null)
        ) {
            $complete = false;
            break;
        }
    }
}
            return [
                'class' => self::sectionStatusClass($complete),
            ];
        }

        // باقي الأنظمة
        $complete =
            filled($get('guarantor_name')) &&
            filled($get('guarantor_phone'));

        return [
            'class' => self::sectionStatusClass($complete),
        ];
    })
    ->schema([

                    // — لو النظام "حالا" → نظهر Repeater (عدة ضمـان)
                    Forms\Components\Repeater::make('guarantors')
                        ->label('الضامنون (خاص بنظام حالا)')
                        ->schema([
                            Forms\Components\TextInput::make('name')->label('اسم الضامن')->required(),
                            Forms\Components\TextInput::make('phone')->label('رقم هاتف الضامن')->required(),
                            Forms\Components\TextInput::make('address')->label('العنوان')->placeholder('عنوان الضامن'),

                            // رفع صورة وجه البطاقة داخل الـ Repeater
                            Forms\Components\FileUpload::make('guarantor_id_image')
                                ->label('بطاقة الضامن (الوجه)')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                                ->directory('installments/guarantors')
                                ->visibility('public')
                                ->image()
                                ->nullable()
                                ->visible(fn($get) => $get('installment_type') !== 'حالا')
                                ->dehydrated(fn($state) => filled($state)),

                            Forms\Components\FileUpload::make('guarantor_id_back_image')
                                ->label('بطاقة الضامن (الظهر)')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                                ->directory('installments/guarantors')
                                ->visibility('public')
                                ->image()
                                ->nullable()
                                ->visible(fn($get) => $get('installment_type') !== 'حالا')
                                ->dehydrated(fn($state) => filled($state)),
                        ])
                        ->visible(fn($get) => $get('installment_type') === 'حالا') // شرط الظهور
                        ->createItemButtonLabel('أضف ضامن')
                        ->collapsible()
                        ->columnSpan('full'),

                    // — الحقول التقليدية (لأي نظام آخر) — تبقى كما هي
                    Forms\Components\TextInput::make('guarantor_name')
                        ->label('اسم الضامن')
                        ->visible(fn($get) => $get('installment_type') !== 'حالا'),

                    Forms\Components\TextInput::make('guarantor_phone')
                        ->label('رقم هاتف الضامن')
                        ->visible(fn($get) => $get('installment_type') !== 'حالا'),

                    Forms\Components\View::make('filament.custom.download')
                        ->dehydrated(false)

                        ->viewData([
                            'url' => fn($record) => $record?->guarantor_id_image
                                ? asset('storage/' . $record->guarantor_id_image)
                                : null,
                            'label' => 'تحميل وجه البطاقة الحالي',
                        ])
                        ->visible(
                            fn($record, $get) =>
                            $record &&
                            $record->guarantor_id_image &&
                            $get('installment_type') !== 'حالا'
                        ),

                    Forms\Components\FileUpload::make('guarantor_id_image')
                        ->label('بطاقة الضامن (الوجه)')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                        ->directory('installments/guarantors')
                        ->visibility('public')
                        ->image()
                        ->nullable()
                        ->visible(fn($get) => $get('installment_type') !== 'حالا'),

                    Forms\Components\View::make('filament.custom.download')
                        ->dehydrated(false)

                        ->viewData([
                            'url' => fn($record) => $record?->guarantor_id_back_image
                                ? asset('storage/' . $record->guarantor_id_back_image)
                                : null,
                            'label' => 'تحميل ظهر البطاقة الحالي',
                        ])
                        ->visible(
                            fn($record, $get) =>
                            $record &&
                            $record->guarantor_id_back_image &&
                            $get('installment_type') !== 'حالا'
                        ),

                    Forms\Components\FileUpload::make('guarantor_id_back_image')
                        ->label('بطاقة الضامن (الظهر)')
                        ->directory('installments/guarantors')
    ->orientImagesFromExif()
    ->imageEditor()
    ->imageEditorAspectRatios([
        '3:4',
        '4:5',
    ])
    ->maxSize(1024)
                        ->visibility('public')
                        ->image()
                        ->nullable()
                        ->visible(fn($get) => $get('installment_type') !== 'حالا'),
                ])
                ->columns(3),

Forms\Components\Section::make('الملاحظات')
    ->icon('heroicon-o-chat-bubble-left-right')
    ->collapsible()
    ->collapsed(false)
    ->extraAttributes([
        'class' => 'ring-1 ring-success-500 border-success-500',
    ])
    ->schema([
        Forms\Components\Textarea::make('notes')
            ->label('ملاحظات')
            ->rows(6)
            ->columnSpanFull()
            ->nullable(),
    ])
    ->columns(1),

            // 🔹 مراجعة الطلب
            // 🔹 مراجعة الطلب
            Forms\Components\Section::make('مراجعة الطلب')
                ->schema(function () {
                    $user = Auth::user();
                    $isAdmin = $user instanceof Staff && $user->is_admin;

                    return [
                        Forms\Components\Select::make('status')
                            ->label('حالة الطلب')
                            ->options([
                                'new' => 'انتظار',
                                'new_request' => 'طلب جديد',
                                'pending' => 'تحت الاستعلام',
                                'work_check' => 'استعلام عمل',
                                'approved' => 'موافقة',
                                'rejected' => 'رفض',
                                'paused' => 'متوقف',
                                'transferred' => 'رد ادارة',
                                'delivered' => 'استلم المكنة',
                                'canceled' => 'الطلب ملغي',
                            ])
                            ->default('new')
                            ->disabled(!$isAdmin)
                            ->dehydrated($isAdmin),

                        Forms\Components\Textarea::make('checks_report')
                            ->label('السبب')
                            ->rows(3)
                            ->disabled(!$isAdmin)
                            ->dehydrated($isAdmin),

                        Forms\Components\View::make('filament.custom.request-activity-timeline')
                            ->label('سجل تعديلات الطلب')
                            ->columnSpanFull()
                            ->dehydrated(false)
                            ->visible(fn($record) => filled($record))
                            ->viewData([
                                'ignore_creation_logs' => true,
                            ]),
                    ];
                })
                ->columns(2),

        ]);
    }

    // ✅ جدول العرض
    public static function table(Table $table): Table
    {
        return $table
            ->paginated([5, 10, 25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('applicant_name')
                    ->label('اسم العميل')
                    ->searchable(), // ✅ البحث بالاسم

                Tables\Columns\TextColumn::make('applicant_phone')
                    ->label('رقم الهاتف')
                    ->searchable(), // ✅ البحث بالهاتف

                Tables\Columns\TextColumn::make('installment_type')
                    ->label('شركة التقسيط')
                    ->sortable(),

                Tables\Columns\TextColumn::make('machine.name')
                    ->label('المكنة'),



                Tables\Columns\TextColumn::make('staff.name')
                    ->label('اسم الموظف')
                    ->sortable()
                    ->searchable(),
               

Tables\Columns\TextColumn::make('deletedBy.name')
    ->label('محذوف بواسطة')
    ->badge()
    ->color('danger')
    ->placeholder('غير محدد')
    ->sortable()
    ->searchable()
    ->visible(
        fn ($livewire): bool =>
            $livewire->activeTab === 'deleted'
    ),

Tables\Columns\TextColumn::make('deleted_at')
    ->label('اتحذف بتاريخ')
    ->dateTime('d/m/Y H:i')
    ->placeholder('غير محدد')
    ->sortable()
    ->visible(
        fn ($livewire): bool =>
            $livewire->activeTab === 'deleted'
    ),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'new' => 'primary',         // طلب جديد
                        'new_request' => 'info',

                        'pending' => 'warning',     // تحت الاستعلام
                        'work_check' => 'warning',  // استعلام عمل (لون أصفر)
                        'approved' => 'success',    // موافقة
                        'rejected' => 'danger',     // رفض
                        'paused' => 'gray',         // متوقف
                        'transferred' => 'info',    // محول (سماوي)
                        'delivered' => 'success',   // استلم المكنة (أخضر)
                        'canceled' => 'danger',     // الطلب ملغي (أحمر)
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state) => match ($state) {
                        'new' => 'انتظار',
                        'new_request' => 'طلب جديد',

                        'pending' => 'تحت الاستعلام',
                        'work_check' => 'استعلام عمل',
                        'approved' => 'موافقة',
                        'rejected' => 'رفض',
                        'paused' => 'متوقف',
                        'transferred' => 'رد ادارة',
                        'delivered' => 'استلم المكنة',
                        'canceled' => 'الطلب ملغي',
                        default => '-',
                    }),




                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i'),
            ])

            // ✅ الفلاتر
            ->filters([
                Tables\Filters\SelectFilter::make('installment_type')
                    ->label('شركة التقسيط')
                    ->options(function () {

                        $user = Auth::user();

                        if (
                            $user &&
                            ($user->is_company_employee ?? false)
                        ) {
                            return $user->installmentSystems()
                                ->pluck(
                                    'installment_systems.name',
                                    'installment_systems.name'
                                )
                                ->toArray();
                        }

                        return InstallmentSystem::query()
                            ->pluck('name', 'name')
                            ->toArray();
                    }),

 Tables\Filters\SelectFilter::make('staff_id')
                    ->label('اسم الموظف')
                    ->options(fn() => [
                        '__without_staff__' => 'بدون اسم',
                    ] + Staff::query()->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $staffId = $data['value'] ?? null;

                        if (blank($staffId)) {
                            return $query;
                        }

                        if ($staffId === '__without_staff__') {
                            return $query->whereNull('staff_id');
                        }

                        return $query->where('staff_id', $staffId);
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options([
                        'new_request' => 'طلب جديد',

                        'pending' => 'تحت الاستعلام',
                        'new' => 'انتظار',
                        'work_check' => 'استعلام عمل',
                        'approved' => 'موافقة',
                        'rejected' => 'رفض',
                        'paused' => 'متوقف',
                        'transferred' => 'رد ادارة',
                        'delivered' => 'استلم المكنة',
                        'canceled' => 'الطلب ملغي',
                    ]),

                // ⭐⭐⭐ الفلتر الجديد ⭐⭐⭐
                Tables\Filters\Filter::make('created_at_date')
                    ->label('تاريخ اليوم')
                    ->form([
                        Forms\Components\DatePicker::make('date')
                            ->label('اختر اليوم')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date'] ?? null, function ($query, $date) {
                                return $query->whereDate('created_at', $date);
                            });
                    }),
            ])



            ->defaultSort('created_at', 'desc')

            ->actions([
                Tables\Actions\ViewAction::make(),

Tables\Actions\Action::make('force_delete')
    ->label('حذف نهائي')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->visible(
        fn ($record, $livewire): bool =>
            static::isHitler()
            && $livewire->activeTab === 'deleted'
            && $record->trashed()
    )
    ->requiresConfirmation()
    ->modalHeading('حذف الطلب نهائيًا')
    ->modalDescription(
        fn ($record) =>
            "تحذير: الطلب رقم #{$record->id} سيتم حذفه نهائيًا ولن يمكن استرجاعه."
    )
    ->modalSubmitActionLabel('حذف نهائي')
    ->action(function ($record): void {

        abort_unless(
            static::isHitler(),
            403
        );

        abort_unless(
            $record->trashed(),
            403
        );

        $record->forceDelete();

        Notification::make()
            ->title('تم الحذف النهائي')
            ->body(
                "تم حذف الطلب رقم #{$record->id} نهائيًا."
            )
            ->success()
            ->send();
    }),
    
   Tables\Actions\Action::make('restore')
    ->label('استرجاع')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('success')
    ->visible(
        fn ($record, $livewire): bool =>
            static::isHitler()
            && $livewire->activeTab === 'deleted'
            && $record->trashed()
    )
    ->requiresConfirmation()
    ->modalHeading('استرجاع الطلب')
    ->modalDescription(
        fn ($record) =>
            "هل تريد استرجاع الطلب رقم #{$record->id}؟"
    )
    ->modalSubmitActionLabel('استرجاع')
    ->action(function ($record): void {

        abort_unless(
            static::isHitler(),
            403
        );

        abort_unless(
            $record->trashed(),
            403
        );

        $record->restore();

        $record->update([
            'deleted_by' => null,
        ]);

        Notification::make()
            ->title('تم استرجاع الطلب')
            ->body(
                "تم استرجاع الطلب رقم #{$record->id} بنجاح."
            )
            ->success()
            ->send();
    }),
              Tables\Actions\Action::make('request_transfer')
    ->label('تحويل الطلب')
    ->icon('heroicon-o-arrow-path')
    ->visible(fn() => Auth::user()->is_admin || Auth::user()->is_super_admin)

    ->form([
        Forms\Components\Select::make('new_staff_id')
            ->label('تحويل إلى')
            ->options(
                fn() => Staff::query()
                    ->pluck('name', 'id')
                    ->toArray()
            )
            ->searchable()
            ->preload()
            ->required(),
    ])

    ->requiresConfirmation()
    ->modalHeading('تحويل الطلب')
    ->modalDescription('سيتم تحويل الطلب مباشرة إلى الموظف المحدد.')
    ->modalSubmitActionLabel('تأكيد التحويل')

    ->action(function ($record, array $data) {

        $user = Auth::user();

        // حماية إضافية
        abort_unless(
            $user && ($user->is_admin || $user->is_super_admin),
            403
        );

        if (! $record->canBeReassignedBy($user)) {
            Notification::make()
                ->title('ممنوع التحويل')
                ->body('ده طلب هتلر، محدش يقدر يسحبه غير هتلر.')
                ->danger()
                ->send();

            return;
        }

        $newStaff = Staff::findOrFail($data['new_staff_id']);

        $oldStaffName = $record->staff?->name ?? 'غير محدد';

        // ✅ تحويل مباشر للأدمن والسوبر أدمن
        $record->update([
            'staff_id' => $newStaff->id,

            // تصفير أي طلب تحويل قديم
            'pending_staff_id' => null,
            'transfer_requested_by' => null,
            'transfer_requested_at' => null,
        ]);

        Notification::make()
            ->title('تم تحويل الطلب بنجاح')
            ->body(
                "تم تحويل الطلب رقم {$record->id} من {$oldStaffName} إلى {$newStaff->name}"
            )
            ->success()
            ->send();
    }),
                Tables\Actions\Action::make('edit_guarded')
                    ->label('تعديل')
                    ->icon('heroicon-o-pencil-square')

                    ->modalHeading('لا يمكن تعديل الطلب')
                    ->modalDescription('الطلب متوقف وعدّى عليه 48 ساعة، لازم ترجع للتيم ليدر بتاعك عشان يفتحه/يعدله.')
                    ->modalSubmitActionLabel('تمام')
                    ->modalCancelAction(false)

                    ->action(function ($record, $livewire) {

                        // ❌ لو مش admin ومقفول → افتح المودال
                        if (!static::isAdminOrSuperAdmin() && static::isLocked($record)) {
                            return;
                        }

                        // ✅ غير كده يدخل على التعديل
                        $livewire->redirect(static::getUrl('edit', ['record' => $record]));
                    })

                    // ⭐ إخفاء المودال للأدمن ⭐
                    ->modalHidden(
                        fn($record) =>
                        static::isAdminOrSuperAdmin() || !static::isLocked($record)
                    ),
            ])

            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_request_transfer')
    ->label('تحويل الطلبات المحددة')
    ->icon('heroicon-o-arrow-path')
    ->color('warning')
    ->visible(
        fn() =>
        Auth::user()->is_admin ||
        Auth::user()->is_super_admin
    )

    ->form([
        Forms\Components\Select::make('new_staff_id')
            ->label('تحويل إلى')
            ->options(
                fn() => Staff::query()
                    ->pluck('name', 'id')
                    ->toArray()
            )
            ->searchable()
            ->preload()
            ->required(),
    ])

    ->requiresConfirmation()
    ->modalHeading('تحويل الطلبات المحددة')
    ->modalDescription(
        'سيتم تحويل جميع الطلبات المحددة مباشرة إلى الموظف المختار.'
    )
    ->modalSubmitActionLabel('تأكيد التحويل')

    ->action(function ($records, array $data): void {

        $user = Auth::user();

        // حماية إضافية
        abort_unless(
            $user &&
            (
                $user->is_admin ||
                $user->is_super_admin
            ),
            403
        );

        $newStaff = Staff::findOrFail($data['new_staff_id']);

        $blocked = $records->reject(fn ($record) => $record->canBeReassignedBy($user));
        $records = $records->diff($blocked);

        if ($blocked->isNotEmpty()) {
            Notification::make()
                ->title("تم استبعاد {$blocked->count()} طلب")
                ->body('دي طلبات هتلر، محدش يقدر يسحبها غير هتلر.')
                ->warning()
                ->send();
        }

        $recordsCount = $records->count();

        if ($recordsCount === 0) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(
            function () use ($records, $newStaff): void {

                foreach ($records as $record) {

                    $record->update([
                        'staff_id' => $newStaff->id,

                        // تنظيف أي تحويل معلق قديم
                        'pending_staff_id' => null,
                        'transfer_requested_by' => null,
                        'transfer_requested_at' => null,
                    ]);
                }
            }
        );

        Notification::make()
            ->title("تم تحويل {$recordsCount} طلب بنجاح")
            ->body(
                "تم تحويل الطلبات مباشرة إلى {$newStaff->name}"
            )
            ->success()
            ->send();
    })

    ->deselectRecordsAfterCompletion(),

               Tables\Actions\BulkAction::make('send_to_deleted')
    ->label('إرسال للمحذوفات')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->requiresConfirmation()
    ->modalHeading('إرسال الطلبات للمحذوفات')
    ->modalDescription(
        'سيتم نقل الطلبات المحددة إلى الطلبات المحذوفة لمراجعتها.'
    )
    ->modalSubmitActionLabel('تأكيد')
    ->action(function ($records): void {

        $user = Auth::user();

        foreach ($records as $record) {

            if ($record->trashed()) {
                continue;
            }

            $record->update([
                'deleted_by' => $user->id,
            ]);

            $record->delete();
        }

        Notification::make()
            ->title('تم نقل الطلبات للمحذوفات')
            ->body(
                "تم نقل {$records->count()} طلب إلى قائمة المحذوفات."
            )
            ->success()
            ->send();
    })
    ->deselectRecordsAfterCompletion(),
            ]);
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveries::route('/'),
            'create' => Pages\CreateDelivery::route('/create'),
            'edit' => Pages\EditDelivery::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return true;
    }
}
