<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryResource\Pages;
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
    if (!$record) return false;

    $user = auth()->user();

    // admin و super_admin دايمًا يقدروا يعدلوا
    if ($user && (
        ($user->is_admin ?? false) ||
        ($user->is_super_admin ?? false) ||
        in_array($user->role ?? null, ['admin', 'super_admin'])
    )) {
        return false;
    }

    // ✅ لو التيم ليدر فتحه للموظف
    if ((bool) $record->employee_editable === true) {
        return false;
    }

    if ($record->status !== 'paused') return false;

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

    return ! static::isLocked($record);
}
public static function canDelete($record): bool
{
    if (static::isSuperAdmin()) return true;

    return ! static::isLocked($record);
}

public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();
    $user = Auth::user();

    if (! $user) {
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
    if ($value === null) return null;

    $arabicIndic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $easternArabicIndic = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $western = ['0','1','2','3','4','5','6','7','8','9'];

    return str_replace($arabicIndic, $western, str_replace($easternArabicIndic, $western, $value));
}

protected static function normalizeApplicantName(?string $value): ?string
{
    if ($value === null) return null;

    // يمنع أ/إ/آ بتحويلهم لـ ا
    $value = str_replace(['أ','إ','آ'], 'ا', $value);

    return $value;
}


    // ✅ نموذج الفورم الكامل
    public static function form(Form $form): Form
    {
        $isCreate = $form->getOperation() === 'create';

        return $form->schema([

            // 🔹 بيانات النظام والمكنة
            // 🔹 بيانات النظام والمكنة
            Forms\Components\Section::make('بيانات النظام والمكنة')
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
    ->disabled(fn () =>
        (bool) (Auth::user()?->is_company_employee ?? false)
    )
    ->dehydrated(true)
    ->searchable()
    ->reactive()
    ->afterStateUpdated(
        fn ($state, callable $set) =>
            self::loadSystemData($state, $set)
    )
    ->afterStateHydrated(
        fn ($state, callable $set) =>
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
    ->disabled(fn () => ! Auth::user()->is_admin && ! Auth::user()->is_super_admin)
    ->dehydrated(true)
    ->default(fn ($record) => $record?->staff_id ?? Auth::id())
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
                                if (!$brandId) return [];
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

                if (! $machine) {
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
    }),               Forms\Components\TextInput::make('deposit')
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
    ->schema([

        Forms\Components\FileUpload::make('price_offer_image')
            ->label('صورة عرض السعر')
            ->directory('installments/price_offers')
            ->visibility('public')
            ->image()
            ->preserveFilenames()
            ->getUploadedFileNameForStorageUsing(fn ($file) => $file->getClientOriginalName())
            ->dehydrated(true)
            ->required(false),

        Forms\Components\View::make('filament.custom.download')
            ->dehydrated(false)  // ✔ أهم خطوة
            ->viewData([
                'url' => fn ($record) =>
                    $record?->price_offer_image
                        ? asset('storage/' . $record->price_offer_image)
                        : null,
                'label' => 'تحميل عرض السعر الحالي',
            ]),

    ])
    ->visible(fn ($get) => in_array($get('installment_type'), [
        'امان',
        'امان (بدون مصاريف ادارية)',
        'امان زيرو مصاريف',
        'امان بدون مصاريف',
        'امان - الجيزة',
        'امان - القاهرة'
    ]))
    ->columns(1),




Forms\Components\Section::make('بيانات العميل')
    ->schema([

       Forms\Components\TextInput::make('applicant_name')
    ->label('اسم العميل')
    ->required()
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $set('applicant_name', self::normalizeApplicantName($state));
    })
    ->dehydrateStateUsing(fn ($state) => self::normalizeApplicantName($state))
    // تأكيد بالـ validation (لو حد لصق نص قبل التحويل)
    ->rule('not_regex:/[أإآ]/u')
    ->validationMessages([
        'not_regex' => 'اسم العميل لا يجب أن يحتوي على (أ، إ، آ).',
    ]),


Forms\Components\TextInput::make('applicant_phone')
    ->label('رقم الهاتف')
    ->required()
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $normalized = self::toEnglishDigits($state);
        // شيل أي شيء غير رقم
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        // اقصى 11 رقم
        $normalized = substr($normalized, 0, 11);

        $set('applicant_phone', $normalized);
    })
    ->dehydrateStateUsing(function ($state) {
        $normalized = self::toEnglishDigits($state);
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        return substr($normalized, 0, 11);
    })
    ->maxLength(11)
    ->minLength(11)
    ->rule('regex:/^\d{11}$/')
    ->unique(ignoreRecord: true)
    ->validationMessages([
        'regex' => 'رقم الهاتف يجب أن يكون 11 رقمًا بالضبط.',
        'unique' => 'رقم الهاتف دا متسجل قبل كدا.',
    ]),

Forms\Components\TextInput::make('applicant_phone_2')
    ->label('رقم الهاتف الثاني')
    ->nullable()
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $normalized = self::toEnglishDigits($state);
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        $normalized = substr($normalized, 0, 11);
        $set('applicant_phone_2', $normalized);
    })
    ->dehydrateStateUsing(function ($state) {
        if ($state === null || $state === '') return null;

        $normalized = self::toEnglishDigits($state);
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        $normalized = substr($normalized, 0, 11);

        return $normalized !== '' ? $normalized : null;
    })
    ->maxLength(11)
    ->rule('regex:/^\d{11}$/')
    ->unique(ignoreRecord: true)
    ->validationMessages([
        'regex'  => 'رقم الهاتف الثاني يجب أن يكون 11 رقمًا بالضبط.',
        'unique' => 'رقم الهاتف الثاني دا متسجل قبل كدا.',
    ]),

Forms\Components\Textarea::make('applicant_address')
    ->label('العنوان')
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $set('applicant_address', self::toEnglishDigits($state));
    })
    ->dehydrateStateUsing(fn ($state) => self::toEnglishDigits($state)),

Forms\Components\TextInput::make('applicant_national_id')
    ->label('الرقم القومي')
    ->default(fn () => request()->query('nid'))   // ✅ يتعبّى تلقائيًا من المودال
    ->required()
    ->reactive()
    ->afterStateUpdated(function ($state, callable $set) {
        $normalized = self::toEnglishDigits($state);
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        $normalized = substr($normalized, 0, 14);
        $set('applicant_national_id', $normalized);
    })
    ->dehydrateStateUsing(function ($state) {
        $normalized = self::toEnglishDigits($state);
        $normalized = preg_replace('/\D+/', '', $normalized ?? '');
        return substr($normalized, 0, 14);
    })
    ->minLength(14)
    ->maxLength(14)
    ->rule('regex:/^\d{14}$/')
    ->unique(ignoreRecord: true),

        Forms\Components\Toggle::make('applicant_age_ok')
            ->label('مستوفي شرط السن'),

Forms\Components\Toggle::make('employee_editable')
    ->label('مسموح للموظف بالتعديل')
    ->visible(fn () => static::isAdminOrSuperAdmin())
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
                ->visible(fn($record) =>
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

    ])
    ->columns(3),


            // 🔹 بيانات الضامن
            Forms\Components\Section::make('بيانات الضامن')
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
    ->directory('installments/guarantors')
    ->visibility('public')
    ->image()
    ->nullable()
    ->visible(fn($get) => $get('installment_type') !== 'حالا')
    ->dehydrated(fn ($state) => filled($state)),

Forms\Components\FileUpload::make('guarantor_id_back_image')
    ->label('بطاقة الضامن (الظهر)')
    ->directory('installments/guarantors')
    ->visibility('public')
    ->image()
    ->nullable()
    ->visible(fn($get) => $get('installment_type') !== 'حالا')
    ->dehydrated(fn ($state) => filled($state)),
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
    ->visible(fn($record, $get) =>
        $record &&
        $record->guarantor_id_image &&
        $get('installment_type') !== 'حالا'
    ),

Forms\Components\FileUpload::make('guarantor_id_image')
    ->label('بطاقة الضامن (الوجه)')
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
    ->visible(fn($record, $get) =>
        $record &&
        $record->guarantor_id_back_image &&
        $get('installment_type') !== 'حالا'
    ),

Forms\Components\FileUpload::make('guarantor_id_back_image')
    ->label('بطاقة الضامن (الظهر)')
    ->directory('installments/guarantors')
    ->visibility('public')
    ->image()
    ->nullable()
    ->visible(fn($get) => $get('installment_type') !== 'حالا'),
                ])
                ->columns(3),

            // 🔹 الحالة الوظيفية
Forms\Components\Section::make('الحالة الوظيفية')
    ->schema([

        // الحالة الوظيفية
Forms\Components\Select::make('work_status')
    ->label('الحالة الوظيفية')
    ->options([
        'employee'      => 'موظف',
        'pension'       => 'صاحب معاش',
        'self_employed' => 'صاحب نشاط',
        'no_income_proof'   => 'دخل حر',
    ])
    ->reactive(),

Forms\Components\TextInput::make('free_work_name')
    ->label('اسم العمل')
    ->required()
    ->visible(fn($get) => $get('work_status') === 'no_income_proof'),

Forms\Components\Textarea::make('free_work_address')
    ->label('عنوان مكان العمل')
    ->rows(2)
    ->columnSpan('full')
    ->visible(fn($get) => $get('work_status') === 'no_income_proof'),


        Forms\Components\Textarea::make('work_address')
            ->label('عنوان العمل')
            ->rows(2)
            ->visible(fn($get) => in_array($get('work_status'), ['employee', 'self_employed'])),


// ================================
// صور إثبات الدخل الحر (دخل حر)
// ================================
Forms\Components\Group::make([
    // عرض الصور القديمة بشكل كبير + زر تحميل
    Forms\Components\View::make('filament.custom.free-income-download-grid')
        ->dehydrated(false)
        ->visible(fn ($record, $get) =>
            $get('work_status') === 'no_income_proof'
            && $record
            && !empty($record->free_income_proof_images)
        )
        ->viewData([
            'files' => fn ($record) => $record?->free_income_proof_images ?? [],
            'label' => 'تحميل إثبات الدخل',
        ])
        ->columnSpan('full'),

    // الرفع (هيفضل زي ما هو)
    Forms\Components\FileUpload::make('free_income_proof_images')
        ->label('صور إثبات (دخل حر)')
        ->disk('public')
        ->directory('installments/free_income_proofs')
        ->visibility('public')
        ->multiple()
        ->image()
        ->imagePreviewHeight('120')
        ->preserveFilenames()
        ->getUploadedFileNameForStorageUsing(fn ($file) => $file->getClientOriginalName())
        ->nullable()
        ->columnSpan('full')
        ->visible(fn ($get) => $get('work_status') === 'no_income_proof'),
])
->columnSpan('full'),


        // ================================
        // مفردات المرتب (موظف)
        // ================================
        Forms\Components\Group::make([
            Forms\Components\View::make('filament.custom.download')
                ->visible(fn($record) =>
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
                ->image()
                ->nullable()
                ->visible(fn($get) => $get('work_status') === 'employee'),
        ]),



        // ================================
        // السجل التجاري (صاحب نشاط)
        // ================================
        Forms\Components\Group::make([
            Forms\Components\View::make('filament.custom.download')
                ->visible(fn($record) =>
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
                ->visible(fn($record) =>
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
                    ->visible(fn($record) =>
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
                ->visible(fn($record) =>
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
                ->image()
                ->nullable()
                ->visible(fn($get) => $get('work_status') === 'pension'),
        ]),

    ])
    ->columns(2),
Forms\Components\Section::make('الملاحظات')
    ->schema([
        Forms\Components\Textarea::make('notes')
            ->label('ملاحظات')
            ->rows(6)
            ->columnSpan('full')
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
                        ->disabled(! $isAdmin)
                        ->dehydrated($isAdmin),

                    Forms\Components\Textarea::make('checks_report')
                        ->label('السبب')
                        ->rows(3)
                        ->disabled(! $isAdmin)
                        ->dehydrated($isAdmin),

                    Forms\Components\View::make('filament.custom.request-activity-timeline')
                        ->label('سجل تعديلات الطلب')
                        ->columnSpanFull()
                        ->dehydrated(false)
                        ->visible(fn ($record) => filled($record))
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
        ->options(fn () => [
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

            'pending'     => 'تحت الاستعلام',
            'new' => 'انتظار',
            'work_check'  => 'استعلام عمل',
            'approved'    => 'موافقة',
            'rejected'    => 'رفض',
            'paused'      => 'متوقف',
            'transferred' => 'رد ادارة',
            'delivered'   => 'استلم المكنة',
            'canceled'    => 'الطلب ملغي',
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
    
Tables\Actions\Action::make('request_transfer')
    ->label('تحويل الطلب')
    ->icon('heroicon-o-arrow-path')
    ->visible(fn () => Auth::user()->is_admin || Auth::user()->is_super_admin)

    ->form([
        Forms\Components\Select::make('new_staff_id')
            ->label('تحويل إلى')
            ->options(Staff::pluck('name', 'id'))
            ->required(),
    ])

    ->action(function ($record, array $data) {

        $user = Auth::user();
        // ✅ لو سوبر أدمن → تحويل مباشر
        if ($user->is_super_admin) {

            $record->update([
                'staff_id' => $data['new_staff_id'],
            ]);

            \Filament\Notifications\Notification::make()
                ->title('تم التحويل بنجاح')
                ->success()
                ->send();

            return;
        }

        // 🔒 لو أدمن → يسجل طلب موافقة
        $record->update([
            'pending_staff_id' => $data['new_staff_id'],
            'transfer_requested_by' => $user->id,
            'transfer_requested_at' => now(),
        ]);

        $newStaff = Staff::find($data['new_staff_id']);
$superAdmins = Staff::where('is_super_admin', 1)->get();

foreach ($superAdmins as $admin) {
    \App\Models\Notification::create([
        'user_id' => $admin->id,
        'title' => 'طلب تحويل جديد',
        'message' => "{$user->name} عايز يحول الطلب رقم {$record->id} من {$record->staff->name} إلى {$newStaff->name}",
        'type' => 'transfer_request',
        'data' => json_encode([
            'request_id' => $record->id,
        ]),
        'is_read' => false,
    ]);
}

        \Filament\Notifications\Notification::make()
            ->title('تم إرسال الطلب للتيم ليدر')
            ->info()
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
        if (! static::isAdminOrSuperAdmin() && static::isLocked($record)) {
            return;
        }

        // ✅ غير كده يدخل على التعديل
        $livewire->redirect(static::getUrl('edit', ['record' => $record]));
    })

    // ⭐ إخفاء المودال للأدمن ⭐
    ->modalHidden(fn ($record) =>
        static::isAdminOrSuperAdmin() || ! static::isLocked($record)
    ),
])

            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_request_transfer')
                    ->label('تحويل الطلبات المحددة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn () => Auth::user()->is_admin || Auth::user()->is_super_admin)
                    ->form([
                        Forms\Components\Select::make('new_staff_id')
                            ->label('تحويل إلى')
                            ->options(fn () => Staff::query()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('تحويل الطلبات المحددة')
                    ->modalDescription('سيتم تطبيق التحويل على كل الطلبات التي قمت بتحديدها.')
                    ->modalSubmitActionLabel('تأكيد التحويل')
                    ->action(function ($records, array $data): void {
                        $user = Auth::user();

                        // حماية إضافية: لا نعتمد على إخفاء الزر فقط.
                        abort_unless(
                            $user && ($user->is_admin || $user->is_super_admin),
                            403
                        );

                        $newStaff = Staff::findOrFail($data['new_staff_id']);
                        $recordsCount = $records->count();

                        \Illuminate\Support\Facades\DB::transaction(function () use (
                            $records,
                            $user,
                            $newStaff
                        ): void {
                            // السوبر أدمن يحول الطلبات مباشرة.
                            if ($user->is_super_admin) {
                                foreach ($records as $record) {
                                    $record->update([
                                        'staff_id' => $newStaff->id,
                                    ]);
                                }

                                return;
                            }

                            // الأدمن يرسل طلب موافقة منفصل لكل طلب محدد.
                            $superAdmins = Staff::where('is_super_admin', 1)->get();

                            foreach ($records as $record) {
                                $oldStaffName = $record->staff?->name ?? 'غير محدد';

                                $record->update([
                                    'pending_staff_id' => $newStaff->id,
                                    'transfer_requested_by' => $user->id,
                                    'transfer_requested_at' => now(),
                                ]);

                                foreach ($superAdmins as $admin) {
                                    \App\Models\Notification::create([
                                        'user_id' => $admin->id,
                                        'title' => 'طلب تحويل جديد',
                                        'message' => "{$user->name} عايز يحول الطلب رقم {$record->id} من {$oldStaffName} إلى {$newStaff->name}",
                                        'type' => 'transfer_request',
                                        'data' => json_encode([
                                            'request_id' => $record->id,
                                        ]),
                                        'is_read' => false,
                                    ]);
                                }
                            }
                        });

                        $notification = Notification::make()
                            ->title(
                                $user->is_super_admin
                                    ? "تم تحويل {$recordsCount} طلب بنجاح"
                                    : "تم إرسال {$recordsCount} طلب تحويل للتيم ليدر"
                            );

                        if ($user->is_super_admin) {
                            $notification->success();
                        } else {
                            $notification->info();
                        }

                        $notification->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\DeleteBulkAction::make(),
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
