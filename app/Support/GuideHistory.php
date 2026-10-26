<?php

namespace App\Support;

use App\Models\{Staff, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Copy existing audit evidence; never infer who performed unrecorded actions. */
class GuideHistory
{
    private array $names = [];

    private function actor(?string $type, $id): array
    {
        $name = null;
        if ($id && in_array($type, [Staff::class, User::class], true)) {
            $key = $type.':'.$id;
            $name = $this->names[$key] ??= $type::query()->whereKey($id)->value('name') ?: 'حساب قديم #'.$id;
        }
        return ['actor_type' => $type, 'actor_id' => $id, 'actor_name' => $name ?: 'الفاعل غير مسجل في السجل القديم'];
    }

    private function row(string $source, object $record, array $values): array
    {
        return $values + ['legacy_source' => $source, 'legacy_id' => $record->id,
            'changes' => '[]', 'context' => json_encode(['source' => 'legacy', 'table' => $source, 'record_id' => $record->id], JSON_UNESCAPED_UNICODE),
            'created_at' => $record->created_at ?? now()];
    }

    public function import(): array
    {
        $counts = [];
        foreach (['activity_log', 'staff_login_logs', 'application_events', 'attendances'] as $sourceIndex => $table) {
            if (!Schema::hasTable($table)) continue;
            $counts[$table] = 0;
            $boundary = DB::table('guide_events')->where('legacy_source', 'history_cutoff')->where('legacy_id', $sourceIndex + 1);
            if (!(clone $boundary)->exists()) {
                DB::table('guide_events')->insertOrIgnore([
                    'legacy_source' => 'history_cutoff', 'legacy_id' => $sourceIndex + 1, 'event' => 'action',
                    'actor_name' => 'النظام', 'subject_label' => 'استيراد السجل القديم',
                    'description' => 'تحديد بداية المرشد واستيراد السجل السابق من '.$table,
                    'context' => json_encode(['table' => $table, 'max_id' => DB::table($table)->max('id') ?? 0]), 'created_at' => now(),
                ]);
            }
            $max = json_decode((clone $boundary)->value('context'), true)['max_id'];
            DB::table($table)->where('id', '<=', $max ?? 0)->orderBy('id')->chunkById(250, function ($records) use ($table, &$counts) {
                $batch = [];
                foreach ($records as $record) {
                    if ($table === 'activity_log') {
                        $props = GuideRecorder::sanitize(json_decode($record->properties ?? '{}', true) ?: []);
                        $event = $record->event ?: (in_array($record->description, ['created', 'updated', 'deleted', 'restored'], true) ? $record->description : 'action');
                        $changes = [];
                        foreach (array_unique(array_merge(array_keys($props['old'] ?? []), array_keys($props['attributes'] ?? []))) as $field) {
                            if (in_array($field, ['created_at', 'updated_at', 'remember_token'], true)) continue;
                            $changes[] = ['field' => GuideRecorder::FIELDS[$field] ?? $field,
                                'before' => $event === 'deleted' ? ($props['attributes'][$field] ?? $props['old'][$field] ?? null) : ($props['old'][$field] ?? null),
                                'after' => $event === 'deleted' ? null : ($props['attributes'][$field] ?? null)];
                        }
                        $subject = (GuideRecorder::LABELS[class_basename($record->subject_type ?? '')] ?? class_basename($record->subject_type ?? 'سجل')).' #'.$record->subject_id;
                        $actor = $this->actor($record->causer_type, $record->causer_id);
                        $batch[] = $this->row($table, $record, $actor + [
                            'event' => $event, 'subject_type' => $record->subject_type, 'subject_id' => $record->subject_id,
                            'subject_label' => $subject, 'description' => $actor['actor_name'].' · '.(\App\Models\GuideEvent::EVENTS[$event] ?? $event).' '.$subject.' (سجل قديم)',
                            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
                        ]);
                    } elseif ($table === 'staff_login_logs') {
                        $actor = $this->actor(Staff::class, $record->staff_id);
                        $batch[] = $this->row($table, $record, $actor + ['event' => 'login', 'subject_type' => Staff::class, 'subject_id' => $record->staff_id,
                            'subject_label' => 'جلسة دخول', 'description' => $actor['actor_name'].' سجّل الدخول (سجل قديم)',
                            'created_at' => $record->logged_in_at ?? $record->created_at,
                            'context' => json_encode(['source' => 'legacy', 'ip' => $record->ip_address], JSON_UNESCAPED_UNICODE)]);
                    } elseif ($table === 'application_events') {
                        $actor = $this->actor(null, null);
                        if (preg_match('/^staff[:#](\d+)$/', $record->actor ?? '', $match)) $actor = $this->actor(Staff::class, $match[1]);
                        $batch[] = $this->row($table, $record, $actor + ['event' => $record->from_status !== $record->to_status ? 'updated' : 'action',
                            'subject_type' => \App\Models\Application::class, 'subject_id' => $record->application_id,
                            'subject_label' => 'طلب #'.$record->application_id,
                            'description' => 'طلب #'.$record->application_id.' · '.GuideRecorder::displayValue($record->from_status, 'الحالة').' ← '.GuideRecorder::displayValue($record->to_status, 'الحالة').' (سجل قديم)',
                            'changes' => json_encode([['field' => 'الحالة', 'before' => $record->from_status, 'after' => $record->to_status]], JSON_UNESCAPED_UNICODE),
                            'context' => json_encode(GuideRecorder::sanitize(['source' => 'legacy', 'original_actor' => $record->actor, 'event_type' => $record->type, 'data' => json_decode($record->data ?? '{}', true)]), JSON_UNESCAPED_UNICODE)]);
                    } else {
                        // Attendance timestamps prove attendance, not who entered/edited it.
                        $employee = $this->actor(Staff::class, $record->staff_id)['actor_name'];
                        $batch[] = $this->row($table, $record, $this->actor(null, null) + ['event' => 'action',
                            'subject_type' => \App\Models\Attendance::class, 'subject_id' => $record->id,
                            'subject_label' => 'حضور وانصراف #'.$record->id,
                            'description' => 'سجل حضور قديم للموظف '.$employee.' · '.substr((string) $record->attendance_date, 0, 10),
                            'created_at' => $record->check_in_at ?? $record->created_at,
                            'changes' => json_encode([['field' => 'وقت الحضور', 'before' => null, 'after' => $record->check_in_at], ['field' => 'وقت الانصراف', 'before' => null, 'after' => $record->check_out_at]], JSON_UNESCAPED_UNICODE)]);
                    }
                }
                $counts[$table] += DB::table('guide_events')->insertOrIgnore($batch);
            });
        }
        return $counts;
    }
}
