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

        'attendance_rules',
        'lat',
'lng',
'distance_m',

    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'is_super_admin' => 'boolean', // ✅ جديد
        'requests_count' => 'integer',
                'attendance_rules' => 'array',
                'lat' => 'float',
'lng' => 'float',
'distance_m' => 'integer',


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
public function whatsappBot()
{
    return $this->hasOne(\App\Models\WhatsappBot::class);
}

}

