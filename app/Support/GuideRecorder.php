<?php

namespace App\Support;

use App\Models\GuideEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/** Central audit trail. Never stores credentials, tokens, or raw request bodies. */
class GuideRecorder
{
    private bool $writing = false;

    public const LABELS = [
        'Machine' => 'مكنة', 'Staff' => 'موظف', 'User' => 'مستخدم',
        'Attendance' => 'حضور وانصراف', 'LegacyAttendance' => 'حضور',
        'InstallmentRequest' => 'طلب تقسيط', 'Application' => 'طلب',
        'Inventory' => 'مخزون', 'InventoryItem' => 'صنف مخزون',
        'TreasuryExpense' => 'مصروف خزينة', 'TreasuryExpenseItem' => 'بند مصروف',
        'Branch' => 'فرع', 'Warehouse' => 'مخزن', 'Brand' => 'شركة',
        'InstallmentSystem' => 'نظام تقسيط', 'InstallmentPlan' => 'خطة تقسيط',
        'Customer' => 'عميل', 'Role' => 'دور وصلاحيات', 'Letter' => 'خطاب',
        'Delivery' => 'تسليم', 'Product' => 'منتج', 'DailyTask' => 'مهمة يومية',
        'DailyTaskReport' => 'تقرير يومي', 'DashboardSetting' => 'إعدادات لوحة التحكم',
        'EmployeeAdvanceRequest' => 'طلب سلفة', 'EmployeeVacationRequest' => 'طلب إجازة',
        'AttendancePoint' => 'نقطة حضور', 'WhatsappBot' => 'بوت واتساب',
        'Ad' => 'إعلان', 'Slider' => 'بانر', 'Team' => 'فريق', 'TeamMember' => 'عضو فريق',
        'Target' => 'هدف', 'Trader' => 'تاجر', 'StockItem' => 'صنف', 'StockGroup' => 'مجموعة أصناف',
        'SiteSetting' => 'إعدادات الموقع', 'AgentSetting' => 'إعدادات البوت', 'BotLesson' => 'درس للبوت',
        'TeachingCase' => 'حالة تعليم', 'TeachingChange' => 'تعديل تعليمي', 'TeachingSession' => 'جلسة تعليم',
        'FormLink' => 'رابط استمارة', 'FormLinkAnswer' => 'إجابة استمارة', 'FormLinkFile' => 'ملف استمارة',
        'DocumentType' => 'نوع مستند', 'RequirementField' => 'حقل مطلوب', 'ApplicationRequirement' => 'متطلب طلب',
        'CustomerType' => 'نوع عميل', 'CustomerAttribute' => 'بيانات عميل', 'EligibilityRule' => 'قاعدة أهلية',
        'DynamicForm' => 'استمارة', 'DynamicFormVersion' => 'نسخة استمارة', 'Answer' => 'إجابة',
        'ApplicationData' => 'بيانات طلب', 'ApplicationDocument' => 'مستند طلب', 'ApplicationEvent' => 'حدث طلب',
        'Post' => 'مقال', 'PostComment' => 'تعليق', 'ClientReview' => 'تقييم عميل',
        'ContactMessage' => 'رسالة تواصل', 'NewsletterSubscriber' => 'مشترك بالنشرة',
        'Offer' => 'عرض', 'Order' => 'أوردر', 'OrderItem' => 'بند أوردر', 'Partner' => 'شريك',
        'PermissionTemplate' => 'قالب صلاحيات', 'EmployeeBonus' => 'مكافأة موظف',
        'AttendancePointExchange' => 'استبدال نقاط حضور', 'AttendanceDeductionRule' => 'قاعدة خصم حضور',
        'BusinessMemory' => 'ذاكرة العمل', 'GeminiApiKey' => 'مفتاح ذكاء اصطناعي', 'GeminiApiKeyModel' => 'موديل مفتاح',
        'WhatsappConversation' => 'محادثة واتساب', 'WhatsappMessage' => 'رسالة واتساب',
        'WhatsappMessageJob' => 'مهمة واتساب', 'Handoff' => 'تحويل محادثة',
        'AiCall' => 'نداء ذكاء اصطناعي', 'AiTrace' => 'تتبع ذكاء اصطناعي', 'AiTraceStep' => 'خطوة ذكاء اصطناعي',
        'AiUsageLog' => 'استهلاك ذكاء اصطناعي', 'AiModelPrice' => 'تسعير موديل',
        'AgentInstructionVersion' => 'نسخة تعليمات البوت', 'StaffLoginLog' => 'سجل دخول موظف',
        'Notification' => 'إشعار', 'SmsLog' => 'رسالة نصية', 'PushSubscription' => 'اشتراك إشعارات',
        'MotorcycleImage' => 'صورة مكنة', 'MessageMedia' => 'مرفق رسالة', 'DailyTaskFile' => 'ملف مهمة',
        'LegacyStatusMapping' => 'ربط حالات الطلبات', 'InstallmentCalculator' => 'حاسبة تقسيط',
    ];

    public const FIELDS = [
        'work_landmark' => 'العمل: العلامة المميزة',
        'work_apartment' => 'العمل: الشقة',
        'work_floor' => 'العمل: الدور',
        'work_building_number' => 'العمل: رقم العقار',
        'work_branch_street' => 'العمل: الشارع الجانبي',
        'work_street' => 'العمل: الشارع',
        'work_area' => 'العمل: المنطقة',
        'work_governorate' => 'العمل: المحافظة',
        'applicant_landmark' => 'السكن: العلامة المميزة',
        'applicant_apartment' => 'السكن: الشقة',
        'applicant_floor' => 'السكن: الدور',
        'applicant_building_number' => 'السكن: رقم العقار',
        'applicant_branch_street' => 'السكن: الشارع الجانبي',
        'applicant_street' => 'السكن: الشارع',
        'applicant_area' => 'السكن: المنطقة',
        'applicant_governorate' => 'السكن: المحافظة',
        'free_work_name' => 'اسم جهة العمل / النشاط',

        'colors' => 'الألوان المتاحة', 'installment_systems' => 'أنظمة التقسيط',
        'features' => 'المميزات', 'aliases' => 'أسماء بديلة', 'specifications' => 'مواصفات المكنة',
        'availability' => 'توافر المكنة', 'cc' => 'سعة المحرك', 'model_year' => 'سنة الموديل',
        'category' => 'الفئة', 'is_featured' => 'ظهور مميز', 'video_url' => 'فيديو المكنة',
        'name' => 'الاسم', 'cash_price' => 'السعر النقدي', 'installment_price' => 'سعر التقسيط',
        'machine_installment_price' => 'سعر المكنة بالتقسيط', 'old_price' => 'السعر السابق',
        'new_price' => 'السعر الجديد', 'status' => 'الحالة', 'staff_id' => 'الموظف المسؤول',
        'machine_id' => 'المكنة', 'installment_system_id' => 'نظام التقسيط',
        'branch_id' => 'الفرع', 'warehouse_id' => 'المخزن', 'role_id' => 'الدور',
        'check_in_at' => 'وقت الحضور', 'check_out_at' => 'وقت الانصراف',
        'attendance_date' => 'تاريخ الحضور', 'notes' => 'ملاحظات', 'amount' => 'المبلغ',
        'deposit' => 'المقدم', 'applicant_name' => 'اسم العميل', 'is_active' => 'نشط',
        'permissions' => 'الصلاحيات', 'monthly_salary' => 'الراتب',
        'phone' => 'رقم الهاتف', 'email' => 'البريد الإلكتروني', 'address' => 'العنوان',
        'national_id' => 'الرقم القومي', 'customer_name' => 'اسم العميل', 'applicant_phone' => 'هاتف العميل',
        'chassis_number' => 'رقم الشاسيه', 'engine_number' => 'رقم الماتور', 'color' => 'اللون',
        'paid' => 'المدفوع', 'remaining' => 'المتبقي', 'admin_fees' => 'المصاريف الإدارية', 'cash' => 'قيمة الكاش',
        'limit' => 'الليمت', 'sold_at' => 'وقت البيع', 'quantity' => 'عدد القطع', 'price' => 'السعر',
        'brand_id' => 'الشركة', 'product_id' => 'الصنف', 'inventory_id' => 'المخزون',
        'customer_action' => 'قرار العميل', 'checks_report' => 'تقرير الاستعلام', 'reason' => 'السبب',
        'attendance_rules' => 'قواعد الحضور', 'late_minutes' => 'دقائق التأخير', 'overtime_minutes' => 'دقائق الوقت الإضافي',
        'deduction_days' => 'أيام الخصم', 'deduction_amount' => 'قيمة الخصم', 'earned_on' => 'يوم احتساب النقاط',
        'count' => 'العدد', 'is_admin' => 'صلاحية مدير', 'is_super_admin' => 'صلاحية مدير عام', 'is_hitler' => 'صلاحية مالك النظام',
        'is_showroom_employee' => 'موظف معرض', 'is_company_employee' => 'موظف شركة', 'is_bot' => 'حساب بوت',
        'title' => 'العنوان', 'description' => 'الوصف', 'body' => 'المحتوى', 'content' => 'المحتوى',
        'image' => 'الصورة', 'images' => 'الصور', 'chassis_image' => 'صورة الشاسيه', 'engine_image' => 'صورة الماتور',
        'display_image' => 'صورة العرض', 'main_image' => 'الصورة الرئيسية', 'thumbnail' => 'الصورة المصغرة', 'cover_image' => 'صورة الغلاف',
        'customer_id_image' => 'صورة البطاقة', 'value' => 'القيمة', 'key' => 'الإعداد', 'phone_number' => 'رقم الهاتف',
        'from_status' => 'الحالة السابقة', 'to_status' => 'الحالة الجديدة', 'type' => 'النوع', 'date' => 'التاريخ',
        'is_returned' => 'مرتجع', 'returned_at' => 'تاريخ الإرجاع', 'due_date' => 'تاريخ الاستحقاق',
    ];

    public static function sanitize(array $values): array
    {
        if (is_string($values['key'] ?? null) && preg_match('/password|token|secret|api.?key|credential|private.?key/i', $values['key']) && array_key_exists('value', $values)) {
            $values['value'] = '[محجوب]';
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|api.?key|authorization|cookie|credential|private.?key|media.?key|otp|two.?factor|recovery.?code/i', (string) $key)) {
                $values[$key] = '[محجوب]';
            } elseif (is_array($value)) {
                $values[$key] = self::sanitize($value);
            } elseif (is_string($value) && is_array($decoded = json_decode($value, true))) {
                $values[$key] = self::sanitize($decoded);
            }
        }

        return $values;
    }

    public static function displayValue(mixed $value, string $field): string
    {
        if ($value === null) { return '—'; }
        if (is_bool($value)) { return $value ? 'نعم' : 'لا'; }
        if (is_array($value)) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }
        $reference = [
            'الموظف المسؤول' => \App\Models\Staff::class, 'المكنة' => \App\Models\Machine::class,
            'الفرع' => \App\Models\Branch::class, 'المخزن' => \App\Models\Warehouse::class,
            'الدور' => \App\Models\Role::class, 'الشركة' => \App\Models\Brand::class,
        ][$field] ?? null;
        if ($reference && is_numeric($value)) {
            $name = $reference::query()->whereKey($value)->value('name');
            return $name ? $name.' (#'.$value.')' : '#'.$value;
        }
        if ($field === 'الحالة') {
            $statuses = DashboardLabels::APPLICATION_STATUS + [
                'new' => 'جديد', 'new_request' => 'تم استلام الطلب', 'pending' => 'قيد المراجعة',
                'work_check' => 'جاري الاستعلام', 'paused' => 'متوقف مؤقتًا', 'transferred' => 'تم التحويل',
                'delivered' => 'تم التسليم', 'canceled' => 'ملغي', 'present' => 'حاضر', 'absent' => 'غائب',
            ];
            return isset($statuses[$value]) ? $statuses[$value] : (string) $value;
        }
        return (string) $value;
    }

    public function model(string $event, Model $model): void
    {
        try {
            $this->recordModel($event, $model);
        } catch (\Throwable $exception) {
            // Audit failures must never interrupt saving a task or its attachments.
            report($exception);
        }
    }

    private function recordModel(string $event, Model $model): void
    {
        if (! str_starts_with(get_class($model), 'App\\Models\\') || $model instanceof GuideEvent) {
            return;
        }

        $attributes = $event === 'updated' ? $model->getChanges() : $model->getAttributes();
        foreach ($model->getHidden() as $hidden) {
            if (array_key_exists($hidden, $attributes)) {
                $attributes[$hidden] = '[محجوب]';
            }
        }
        $attributes = array_diff_key($attributes, array_flip(['created_at', 'updated_at', 'remember_token']));
        if ($event === 'updated' && $attributes === []) {
            return;
        }

        $changes = [];
        $original = self::sanitize($model->getRawOriginal());
        $rawAttributes = $model->getAttributes();
        if (array_key_exists('value', $attributes) && is_string($rawAttributes['key'] ?? null)
            && preg_match('/password|token|secret|api.?key|credential|private.?key/i', $rawAttributes['key'])) {
            $attributes['value'] = '[محجوب]';
        }
        foreach (self::sanitize($attributes) as $field => $value) {
            $old = in_array($field, $model->getHidden(), true) ? '[محجوب]' : ($original[$field] ?? null);
            $changes[] = [
                'field' => self::FIELDS[$field] ?? $field,
                'before' => $event === 'created' ? null : $old,
                'after' => $event === 'deleted' ? null : $value,
            ];
        }

        $label = self::LABELS[class_basename($model)] ?? class_basename($model);
        // getAttribute() on a missing column can invoke a same-named method as a
        // relationship (DailyTaskFile::name() returns a string, not a Relation).
        $name = null;
        foreach (['name', 'applicant_name', 'title', 'original_name'] as $field) {
            $candidate = $rawAttributes[$field] ?? null;
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $name = in_array($field, $model->getHidden(), true) ? '[محجوب]' : $candidate;
                break;
            }
        }
        $subject = $label.' #'.$model->getKey().($name ? ' — '.mb_substr((string) $name, 0, 100) : '');
        $verb = match ($event) {
            'created' => 'أضاف', 'deleted' => 'حذف', 'restored' => 'استعاد', default => 'عدّل',
        };
        $summary = $verb.' '.$subject;
        if ($event === 'updated') {
            $summary .= ' · '.implode('، ', array_column(array_slice($changes, 0, 4), 'field'));
            foreach ($changes as $change) {
                if (in_array($change['field'], ['الحالة', 'الموظف المسؤول', 'السعر النقدي', 'سعر التقسيط'], true)) {
                    $summary .= ' · '.$change['field'].': '.self::displayValue($change['before'], $change['field']).' ← '.self::displayValue($change['after'], $change['field']);
                }
            }
        }
        $this->write($event, $subject, $summary, $changes, $model);
        app(GuideMutations::class)->modelsRecorded++;
    }

    public function actor(): ?Model
    {
        foreach (['filament', 'staff', 'web'] as $guard) {
            if ($user = Auth::guard($guard)->user()) {
                return $user;
            }
        }

        return null;
    }

    public function write(string $event, string $subject, string $description, array $changes = [], ?Model $model = null, ?Model $actor = null, array $context = []): void
    {
        if ($this->writing) {
            return;
        }
        $this->writing = true;
        try {
            // Also permits deploying code before the migration without breaking business operations.
            if (! Schema::hasTable('guide_events')) {
                return;
            }
            $actor ??= $this->actor();
            $actorName = $actor instanceof \App\Models\Customer ? 'العميل' : ($actor?->getAttribute('name') ?? 'النظام / عملية تلقائية');
            GuideEvent::create([
                'event' => $event, 'actor_type' => $actor ? get_class($actor) : null,
                'actor_id' => $actor?->getKey(), 'actor_name' => $actorName,
                'subject_type' => $model ? get_class($model) : null, 'subject_id' => $model?->getKey(),
                'subject_label' => mb_substr($subject, 0, 255), 'description' => $actorName.' '.$description,
                'changes' => $changes,
                'context' => self::sanitize($context + [
                    'ip' => app()->runningInConsole() ? null : request()->ip(),
                    'path' => app()->runningInConsole() ? null : request()->path(),
                    'source' => app()->runningInConsole() ? 'console' : 'web',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        } finally {
            $this->writing = false;
        }
    }
}
