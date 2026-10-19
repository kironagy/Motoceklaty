<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** "من 5 دقايق" - how staff say it, instead of Carbon's English or formal Arabic. */
class EgyptianTime
{
    public static function ago(?Carbon $time): ?string
    {
        if (! $time) {
            return null;
        }

        $minutes = (int) floor($time->diffInMinutes(now(), true));

        return match (true) {
            $minutes < 1 => 'دلوقتي',
            $minutes === 1 => 'من دقيقة',
            $minutes === 2 => 'من دقيقتين',
            $minutes < 11 => "من {$minutes} دقايق",
            $minutes < 60 => "من {$minutes} دقيقة",
            $minutes < 120 => 'من ساعة',
            $minutes < 180 => 'من ساعتين',
            $minutes < 60 * 11 => 'من '.intdiv($minutes, 60).' ساعات',
            $minutes < 60 * 24 => 'من '.intdiv($minutes, 60).' ساعة',
            $minutes < 60 * 48 => 'امبارح '.$time->format('g:i'),
            $minutes < 60 * 24 * 7 => 'من '.intdiv($minutes, 60 * 24).' أيام',
            default => $time->format('Y-m-d'),
        };
    }
}
