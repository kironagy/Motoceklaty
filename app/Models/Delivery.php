<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Delivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'installment_request_id',
        'name',
        'address',
        'phone',

        // ✅ بطاقات
        'applicant_id_front',
        'applicant_id_back',
        'guarantor_id_front',
        'guarantor_id_back',

        // ✅ الحالة الوظيفية
        'work_status',
        'salary_slip_file',
        'pension_statement_file',
        'commercial_reg_file',
        'tax_card_file',

        // ✅ ملفات إضافية
        'documents',
        'staff_id',
    ];

    protected $casts = [
        'documents' => 'array',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function installmentRequest()
    {
        return $this->belongsTo(\App\Models\InstallmentRequest::class);
    }
}
