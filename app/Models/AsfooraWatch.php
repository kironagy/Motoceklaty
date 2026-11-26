<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner 2026-11-26: a background check «عصفورة» repeats (new requests every hour) until he says stop. */
class AsfooraWatch extends Model
{
    /** a watch nobody stopped ends by itself after this long */
    public const MAX_DAYS = 7;

    protected $fillable = ['asfoora_chat_id', 'staff_id', 'kind', 'question', 'filters', 'every_minutes', 'last_checked_at', 'next_run_at', 'is_active'];

    protected $casts = ['filters' => 'array', 'last_checked_at' => 'datetime', 'next_run_at' => 'datetime', 'is_active' => 'boolean'];

    public function describe(): string
    {
        $every = $this->every_minutes % 60 === 0 ? ($this->every_minutes === 60 ? 'كل ساعة' : 'كل '.($this->every_minutes / 60).' ساعات') : 'كل '.$this->every_minutes.' دقيقة';

        return ($this->kind === 'new_requests' ? 'الطلبات الجديدة'.(($this->filters['source'] ?? null) === 'bot' ? ' من البوت' : '') : '«'.mb_substr((string) $this->question, 0, 60).'»').' '.$every;
    }
}
