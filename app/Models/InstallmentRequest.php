<?php

namespace App\Models;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;

class InstallmentRequest extends Model
{


 use LogsActivity;
 
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
        'machine_id',
        'whatsapp_conversation_id',
        'installment_type',
        'months',
'machine_installment_price',
        // 🔹 بيانات العميل
        'applicant_name',
        'employee_editable',
        'applicant_phone',
          'applicant_phone_2',
    'free_income_proof_images',
    'pending_staff_id',
    'transfer_requested_by',
    'transfer_requested_at',
        'applicant_address',
        'applicant_national_id',
        'applicant_id_image',
        'applicant_id_back_image',
        'applicant_birthdate',
        'applicant_age_ok',
        'medical_card_image',
        'selfie_image',
        'notes',
        'price_offer_image',
        'deposit',
        'work_status',
        'free_work_name',
        'free_work_address',
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
        'work_address', // ✅ عنوان العمل الجديد

        // 🔹 موظف
        'salary_amount',
        'salary_issue_date',
        'salary_slip_file',

        // 🔹 معاش
        'pension_amount',
        'pension_statement_file', // ✅ تمت الإضافة هنا

        // 🔹 صاحب نشاط
        'commercial_reg_file',
        'commercial_reg_expiry',
        'tax_card_file',
        'tax_card_expiry',
        'place_video',

        // 🔹 الحالة والتحقق
        'status',
        'checks_report',

        // 🔹 الموظف المسئول
        'staff_id',
    ];


    protected $casts = [
        'applicant_age_ok'    => 'boolean',
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

    public function staff()
    {
        return $this->belongsTo(Staff::class);
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

}
