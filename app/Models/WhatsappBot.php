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

    /**
     * A bot owns its customers: deleting it cascades to customers ->
     * applications -> their data and documents. It once failed only because
     * one installment request happened to reference an application; without
     * that, every customer's data would have gone. A bot with customer data
     * is deactivated, never deleted.
     */
    public function hasCustomerData(): bool
    {
        return Customer::where('whatsapp_bot_id', $this->id)->exists()
            || WhatsappConversation::where('whatsapp_bot_id', $this->id)->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $bot) {
            if ($bot->hasCustomerData()) {
                throw new \App\Exceptions\BotHasCustomerDataException($bot->id);
            }
        });
    }
}

