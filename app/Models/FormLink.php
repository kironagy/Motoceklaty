<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One customer's link to an application form (Dynamic Links). */
class FormLink extends Model
{
    /** Still waiting for the customer: follow-ups, reuse and the bot's facts look at these. */
    public const OPEN_STATUSES = ['sent', 'opened', 'in_progress'];

    public const STATUS_LABELS = [
        'sent' => 'اتبعت',
        'opened' => 'اتفتح',
        'in_progress' => 'بيملا',
        'submitted' => 'اتقدم',
        'expired' => 'انتهى',
        'cancelled' => 'اتلغى',
    ];

    protected $fillable = [
        'token', 'dynamic_form_version_id', 'customer_id', 'whatsapp_conversation_id', 'whatsapp_phone',
        'created_by_type', 'created_by_staff_id', 'prefill', 'status', 'purpose', 'staff_note', 'renewed_from_id',
        'first_opened_at', 'last_activity_at', 'submitted_at', 'expires_at', 'followups_sent', 'last_followup_at',
        'receipt_status', 'application_id', 'installment_request_id',
    ];

    protected $casts = [
        'prefill' => 'array',
        'first_opened_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_followup_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(DynamicFormVersion::class, 'dynamic_form_version_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function installmentRequest(): BelongsTo
    {
        return $this->belongsTo(InstallmentRequest::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormLinkAnswer::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(FormLinkFile::class);
    }

    public function url(): string
    {
        return rtrim((string) (config('agent.form_links.base_url') ?: config('app.url')), '/').'/f/'.$this->token;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return array<string, mixed> field key => saved value */
    public function answerValues(): array
    {
        return $this->answers()->pluck('value', 'field_key')->all();
    }
}
