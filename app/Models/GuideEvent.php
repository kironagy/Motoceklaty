<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuideEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changes' => 'array', 'context' => 'array', 'created_at' => 'datetime'];

    public const EVENTS = [
        'created' => 'إضافة', 'updated' => 'تعديل', 'deleted' => 'حذف',
        'restored' => 'استعادة', 'login' => 'دخول', 'logout' => 'خروج', 'action' => 'إجراء',
    ];

    public function scopePeople($query)
    {
        return $query->whereIn('event', ['created', 'updated', 'deleted', 'restored', 'login', 'logout'])->whereNotNull('actor_id')->where(function ($q) {
            $q->where(function ($staff) {
                $staff->where('actor_type', Staff::class)->whereNotIn('actor_id', Staff::query()->where('is_bot', true)->select('id'));
            })->orWhere('actor_type', User::class);
        })->where(function ($q) {
            $q->whereNull('subject_type')->orWhereNotIn('subject_type', [StaffLoginLog::class,
                WhatsappMessageJob::class, AiCall::class, AiTrace::class, AiTraceStep::class, AiUsageLog::class]);
        });
    }

    public function subjectTitle(): string
    {
        if (in_array($this->event, ['login', 'logout'], true)) return 'الدخول للسيستم';
        $label = \App\Support\GuideRecorder::LABELS[class_basename($this->subject_type ?? '')] ?? 'إجراء بالداشبورد';
        $name = str_contains($this->subject_label ?? '', ' — ') ? explode(' — ', $this->subject_label, 2)[1] : null;
        return $label.($this->subject_id ? ' #'.$this->subject_id : '').($name ? ' — '.$name : '');
    }

    public function readableChanges(): array
    {
        $changes = [];
        foreach ($this->getAttribute('changes') ?? [] as $change) {
            $field = $change['field'] ?? '';
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'turn_id', 'last_activity_at', 'last_inbound_at', 'last_outbound_at', 'batch_uuid'], true)) continue;
            if (preg_match('/password|token|secret|api.?key|private.?key|media.?key|otp|two.?factor|recovery.?code/i', $field)) continue;
            if (!isset(\App\Support\GuideRecorder::FIELDS[$field]) && preg_match('/^[a-zA-Z_]+$/', $field)) {
                $image = is_string($change['after'] ?? null) && preg_match('/\.(png|jpe?g|webp|gif|svg|avif)$/i', $change['after']);
                $change['field'] = $image ? 'الصورة' : 'بيانات إضافية';
            } else {
                $change['field'] = \App\Support\GuideRecorder::FIELDS[$field] ?? $field;
            }
            $changes[] = $change;
        }
        return $changes;
    }

    public function changeSummary(): string
    {
        if ($this->event === 'login') return 'دخل السيستم';
        if ($this->event === 'logout') return 'خرج من السيستم';
        if ($this->event === 'deleted') return 'حذف '.$this->subjectTitle();
        if ($this->event === 'created') return 'أضاف '.$this->subjectTitle();
        if ($this->event === 'restored') return 'استعاد '.$this->subjectTitle();
        $parts = [];
        foreach (array_slice($this->readableChanges(), 0, 3) as $change) {
            if (str_contains($change['field'], 'صور')) {
                $parts[] = 'غيّر '.$change['field'];
                continue;
            }
            if (is_array($change['before'] ?? null) || is_array($change['after'] ?? null)) {
                $parts[] = 'عدّل '.$change['field'];
                continue;
            }
            $before = $this->shortValue($change['before'] ?? null, $change['field']);
            $after = $this->shortValue($change['after'] ?? null, $change['field']);
            $parts[] = $change['field'].' من «'.$before.'» إلى «'.$after.'»';
        }
        if ($parts) return implode(' • ', $parts);
        if ($this->event === 'updated') return 'عدّل بيانات '.$this->subjectTitle();
        $operations = data_get($this->context, 'operations', []);
        if ($operations) {
            $labels = ['machines' => 'المكن', 'inventories' => 'المخزون', 'inventory_items' => 'قطع المخزون', 'staff' => 'الموظفين',
                'letters' => 'الجوابات', 'attendances' => 'الحضور والانصراف', 'installment_requests' => 'طلبات التقسيط', 'applications' => 'الطلبات',
                'attendance_points' => 'نقط الموظفين', 'dashboard_settings' => 'إعدادات الداشبورد'];
            return implode(' • ', array_map(function ($operation) use ($labels) {
                [$verb, $table] = array_pad(explode(' ', $operation, 2), 2, '');
                return $verb.' '.($labels[$table] ?? 'بيانات القسم');
            }, array_slice($operations, 0, 3)));
        }
        return 'نفّذ إجراء من أزرار الصفحة';
    }

    public function shortValue($value, string $field): string
    {
        if ($value === null || $value === '') return 'غير محدد';
        if (is_array($value)) return 'تفاصيل متعددة';
        if (str_contains($field, 'سعر') || str_contains($field, 'السعر') || in_array($field, ['المبلغ', 'المقدم', 'المدفوع', 'المتبقي'], true)) {
            if (is_numeric($value)) return number_format((float) $value, 0).' جنيه';
        }
        $text = \App\Support\GuideRecorder::displayValue($value, $field);
        return mb_strimwidth(preg_replace('/\s+/u', ' ', $text), 0, 110, '…');
    }

    public function color(): string
    {
        return match ($this->event) {
            'created', 'restored' => 'success', 'deleted' => 'danger',
            'updated' => 'warning', 'login' => 'info', default => 'gray',
        };
    }
}
