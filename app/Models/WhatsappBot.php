<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappBot extends Model
{
 protected $fillable = [
    'name',
    'staff_id',
    'mode',
    'whatsapp_phone_number',
    'whatsapp_phone_number_id',
    'qr_code',
    'session_status',
    'connected_at',
    'is_active',
    'notes',
];

    // علاقة البوت بالموظف
    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}

