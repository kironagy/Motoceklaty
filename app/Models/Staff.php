<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Staff extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'staff';

    protected $fillable = [
        'name',
        'email',
        'password',
        'requests_count',
        'is_admin',
        'is_super_admin',   // ✅ جديد
        'referral_code',
            'is_bot',
'is_company_employee',
'installment_system_id',
'is_hitler',
        'attendance_rules',
        'role_id',
        'permissions',
        'warehouse_id',
        'branch_id',
        'is_showroom_employee',
        'monthly_salary',
        'attendance_time',
        'departure_time',
        'lat',
'lng',
'distance_m',

    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'is_super_admin' => 'boolean', // ✅ جديد
        'requests_count' => 'integer',
        'is_company_employee' => 'boolean',
        'is_showroom_employee' => 'boolean',
        'monthly_salary' => 'decimal:2',
        'permissions' => 'array',

    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // ✅ تشفير كلمة المرور عند الإنشاء أو التحديث
    public function setPasswordAttribute($value): void
    {
        if (blank($value)) {
            return;
        }

        // لو اتبعت hash جاهز سيبه زي ما هو
        if (Str::startsWith($value, '$2y$') || Str::startsWith($value, '$argon2')) {
            $this->attributes['password'] = $value;
            return;
        }

        $this->attributes['password'] = Hash::make($value);
    }
public function installmentSystems()
{
    return $this->belongsToMany(
        InstallmentSystem::class,
        'installment_system_staff'
    );
}
    // ✅ صلاحيات
    public function isSuperAdmin(): bool
    {
        return (bool) ($this->is_super_admin ?? false);
    }

    public function isAdmin(): bool
    {
        // السوبر أدمن يعتبر أدمن ضمنيًا
        return $this->isSuperAdmin() || (bool) ($this->is_admin ?? false);
    }

    // ✅ توليد كود إحالة فريد تلقائيًا عند إنشاء الموظف
    protected static function booted(): void
    {
        static::creating(function (self $staff) {
            if (empty($staff->referral_code)) {
                $baseCode = 'em-' . Str::slug($staff->name);

                $uniqueCode = $baseCode;
                $counter = 1;

                while (self::where('referral_code', $uniqueCode)->exists()) {
                    $uniqueCode = $baseCode . '-' . $counter;
                    $counter++;
                }

                $staff->referral_code = $uniqueCode;
            }
        });
    }
    public function attendances()
    {
        return $this->hasMany(\App\Models\Attendance::class, 'staff_id');
    }

    public function legacyAttendances()
    {
        return $this->hasMany(\App\Models\LegacyAttendance::class, 'staff_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Models\Warehouse::class);
    }

    /** The branch he works at; attendance is checked against its location. */
    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    public function attendanceDeductionRules()
    {
        return $this->hasMany(\App\Models\AttendanceDeductionRule::class, 'staff_id');
    }

    public function isShowroomEmployee(): bool
    {
        return (bool) $this->is_showroom_employee;
    }
public function role()
{
    return $this->belongsTo(\App\Models\Role::class);
}

public function whatsappBot()
{
    return $this->hasOne(\App\Models\WhatsappBot::class);
}

}

