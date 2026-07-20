<?php

namespace App\Services;

use App\Models\Staff;
use Carbon\Carbon;

class AttendanceRulesService
{
    public function evaluate(Staff $staff, Carbon $now): array
    {
        $rules = $staff->attendance_rules ?? [];

        $officialTime = $this->normalizeTime($rules['official_time'] ?? '13:00');
        $periods = $rules['periods'] ?? [];
        $afterLast = (float) ($rules['after_last_deduction'] ?? 0);

        $nowSeconds = $this->toSeconds($now->format('H:i'));
        $officialSeconds = $this->toSeconds($officialTime);

        // قبل وقت الحضور الرسمي => بدون خصم (اعتبره مش متأخر)
        if ($nowSeconds < $officialSeconds) {
            return [
                'is_late' => false,
                'penalty_days' => 0,
                'applied_rule' => [
                    'type' => 'before_official',
                    'official_time' => $officialTime,
                ],
            ];
        }

        // نبحث في الفترات ونختار اللي الوقت وقع فيها
        $matched = null;
        foreach ($periods as $p) {
            $from = $this->normalizeTime($p['from'] ?? '');
            $to   = $this->normalizeTime($p['to'] ?? '');

            if (! $from || ! $to) {
                continue;
            }

            $fromSeconds = $this->toSeconds($from);
            $toSeconds   = $this->toSeconds($to);

            // دعم لو فترة بتعدّي منتصف الليل (اختياري)
            $inRange = $fromSeconds <= $toSeconds
                ? ($nowSeconds >= $fromSeconds && $nowSeconds <= $toSeconds)
                : ($nowSeconds >= $fromSeconds || $nowSeconds <= $toSeconds);

            if ($inRange) {
                $matched = $p;
                break; // مفيش تداخل عندك أصلاً (انت مانعه في الفورم)
            }
        }

        // لو داخل فترة
        if ($matched) {
            $penalty = (float) ($matched['deduction'] ?? $matched['penalty_days'] ?? $matched['penalty'] ?? 0);

            return [
                'is_late' => $penalty > 0,
                'penalty_days' => $penalty,
                'applied_rule' => $matched,
            ];
        }

        // لو بعد آخر فترة => خصم ثابت after_last_deduction
        $lastToSeconds = $this->maxToSeconds($periods);

        if ($nowSeconds > $lastToSeconds && $lastToSeconds > 0) {
            return [
                'is_late' => $afterLast > 0,
                'penalty_days' => $afterLast,
                'applied_rule' => [
                    'type' => 'after_last',
                    'after_last_deduction' => $afterLast,
                ],
            ];
        }

        // لو ما وقعش في أي فترة (مثلاً فجوة بين الرسمي وأول فترة) => بدون خصم
        return [
            'is_late' => false,
            'penalty_days' => 0,
            'applied_rule' => null,
        ];
    }

    private function normalizeTime(?string $time): string
    {
        if (! $time) return '';

        $t = trim($time);

        // لو راجع "13:00:00" من TimePicker نخليه "13:00"
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) {
            return substr($t, 0, 5);
        }

        // لو راجع "01:00 PM" نخليه زي ما هو بس هنحوّله لتنسيق 24 ساعة جوه toSeconds
        return $t;
    }

    private function toSeconds(string $time): int
    {
        $t = trim($time);

        try {
            // لو فيه AM/PM
            if (str_contains($t, 'AM') || str_contains($t, 'PM')) {
                $c = Carbon::createFromFormat('h:i A', $t);
                return ($c->hour * 3600) + ($c->minute * 60);
            }

            // 24 ساعة "H:i"
            $t = $this->normalizeTime($t);
            [$h, $m] = array_map('intval', explode(':', $t));
            return ($h * 3600) + ($m * 60);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function maxToSeconds(array $periods): int
    {
        $max = 0;

        foreach ($periods as $p) {
            $to = $this->normalizeTime($p['to'] ?? '');
            if (! $to) continue;

            $max = max($max, $this->toSeconds($to));
        }

        return $max;
    }
}

