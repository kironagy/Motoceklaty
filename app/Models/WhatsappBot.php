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
    'proxy_url',
    'qr_code',
    'session_status',
    'wa_limit_level',
    'wa_limit_until',
    'wa_limit_since',
    'wa_limit_detail',
    'connected_at',
    'is_active',
    'notes',
];

    // The bots page calls ->locale()->diffForHumans() on it; uncast it was a string and the page crashed.
    protected $casts = [
        'connected_at' => 'datetime',
        'wa_limit_until' => 'datetime',
        'wa_limit_since' => 'datetime',
        'wa_limit_detail' => 'array',
        'proxy_url' => 'encrypted',
    ];

    protected $hidden = ['proxy_url'];

    /** Owner 2026-11-15: WhatsApp's warning/restriction on this number while it lasts, else null. */
    public function activeLimit(): ?string
    {
        if (! $this->wa_limit_level || ($this->wa_limit_until && $this->wa_limit_until->isPast())) {
            return null;
        }

        return $this->wa_limit_level;
    }

    /** Share of the normal send caps this number may use now (1 = no limit, 0 = stopped). */
    public static function limitFactor(?string $level): float
    {
        return match ($level) {
            'first_warning' => 0.5,
            'second_warning' => 0.25,
            'capped', 'timelock' => 0.0,
            default => 1.0,
        };
    }

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

