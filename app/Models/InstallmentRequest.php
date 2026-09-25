<?php

namespace App\Models;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class InstallmentRequest extends Model
{


 use LogsActivity;
  use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'checks_report',
                'staff_id',
                'installment_type',
                'machine_id',
                'machine_installment_price',
                'deposit',
                'applicant_name',
                'applicant_phone',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('installment_request');
    }
 
 
 
 
  protected $fillable = [
    'application_id',
    'request_type',

    // 🔹 بيانات المكنة والنظام
    'machine_id',
    'whatsapp_conversation_id',
    'installment_type',
    'months',
    'machine_installment_price',
    'deposit',

    // 🔹 بيانات العميل
    'applicant_name',
    'employee_editable',
    'applicant_phone',
    'applicant_phone_2',
    'applicant_address',
    'applicant_national_id',
    'applicant_id_image',
    'applicant_id_back_image',
    'applicant_birthdate',
    'applicant_age_ok',
    'medical_card_image',
    'selfie_image',
    'price_offer_image',
    'notes',

    // 🔹 العنوان السكني
    'applicant_building_number',
    'applicant_street',
    'applicant_branch_street',
    'applicant_governorate',
    'applicant_area',
    'applicant_landmark',
    'applicant_floor',
    'applicant_apartment',

    // 🔹 بيانات الضامن
    'guarantor_name',
    'guarantor_national_id',
    'guarantor_id_image',
    'guarantor_id_back_image',
    'guarantor_birthdate',
    'guarantor_age_ok',
    'guarantor_phone',
    'guarantors',

    // 🔹 الحالة الوظيفية
    'work_status',

    // 🔹 عنوان العمل
    'work_address',
    'work_building_number',
    'work_street',
    'work_branch_street',
    'work_governorate',
    'work_area',
    'work_landmark',
    'work_floor',
    'work_apartment',

    // 🔹 دخل حر
    'free_work_name',
    'free_work_address',
    'free_income_proof_images',

    // 🔹 موظف
    'salary_amount',
    'salary_issue_date',
    'salary_slip_file',

    // 🔹 معاش
    'pension_amount',
    'pension_statement_file',

    // 🔹 صاحب نشاط
    'commercial_reg_file',
    'commercial_reg_expiry',
    'tax_card_file',
    'tax_card_expiry',
    'place_video',

    // 🔹 التحويلات
    'pending_staff_id',
    'transfer_requested_by',
    'transfer_requested_at',

    // 🔹 الحالة والتحقق
    'status',
    'checks_report',

    // 🔹 الموظف المسؤول
    'staff_id',
];

    protected $casts = [
        'applicant_age_ok'    => 'boolean',
            'request_type' => 'string',

        'guarantor_age_ok'    => 'boolean',
        'salary_issue_date'   => 'date',
        'applicant_birthdate' => 'date',
        'guarantor_birthdate' => 'date',
        'checks_report'       => 'array',
        'free_income_proof_images' => 'array',
        'notes'       => 'string',
        'status_updated_at' => 'datetime', 
        'transfer_requested_at' => 'datetime',
        'guarantors' => 'array',
        'place_video' => 'array',
        'status_updated_at' => 'datetime',
        'status_updated_by' => 'integer',

    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function whatsappConversation()
    {
        return $this->belongsTo(WhatsappConversation::class);
    }

    /** T14: the Application this legacy request was projected from, if any. */
    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
    public function scopeNormal($query)
{
    return $query->where('request_type', 'normal');
}

public function scopeFake($query)
{
    return $query->where('request_type', 'fake');
}
protected static function booted()
{
    static::creating(function ($model) {
        if (auth('staff')->check()) {
            $model->staff_id = auth('staff')->id();
        } elseif (auth('filament')->check()) {
            $model->staff_id = auth('filament')->id();
        }
    });

    static::updating(function ($model) {
        // طلبات هتلر محدش يسحبها غير هتلر
        if ($model->isDirty('staff_id') && ! $model->canBeReassignedBy(static::currentActor())) {
            $model->staff_id = $model->getOriginal('staff_id');
            $model->pending_staff_id = $model->getOriginal('pending_staff_id');
        }

        // لو الحالة اتغيرت فقط
        if ($model->isDirty('status')) {
            $model->status_updated_at = now();

            if (auth('staff')->check()) {
                $model->status_updated_by = auth('staff')->id();
            } elseif (auth('filament')->check()) {
                $model->status_updated_by = auth('filament')->id();
            }
        }
    });
}

public static function currentActor(): ?Staff
{
    $user = auth('filament')->user() ?? auth('staff')->user();

    return $user instanceof Staff ? $user : null;
}

public function isOwnedByHitler(): bool
{
    $ownerId = $this->getOriginal('staff_id') ?? $this->staff_id;

    return $ownerId !== null
        && Staff::whereKey($ownerId)->where('is_hitler', true)->exists();
}

public function canBeReassignedBy(?Staff $actor): bool
{
    return ! $this->isOwnedByHitler() || (bool) $actor?->is_hitler;
}

public function pendingStaff()
{
    return $this->belongsTo(Staff::class, 'pending_staff_id');
}

public function transferRequester()
{
    return $this->belongsTo(Staff::class, 'transfer_requested_by');
}
    public function statusUpdater()
{
    return $this->belongsTo(\App\Models\Staff::class, 'status_updated_by');
}
public function deletedBy()
{
    return $this->belongsTo(Staff::class, 'deleted_by');
}

}
