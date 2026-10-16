<?php

// Attendance points (App\Domain\Attendance\AttendancePoints). The amounts, green counts and minute
// limits below are defaults: the dashboard settings page overrides them.
return [
    // Late beyond this many minutes after the official time = red point.
    'grace_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', 0),

    // Late this many minutes or more = black point.
    'black_after_minutes' => (int) env('ATTENDANCE_BLACK_AFTER_MINUTES', 180),

    'red_amount' => (float) env('ATTENDANCE_RED_AMOUNT', 200),
    'black_amount' => (float) env('ATTENDANCE_BLACK_AMOUNT', 1000),

    // Greens earned after a red / black that cancel it.
    'greens_for_red' => 2,
    'greens_for_black' => 5,

    // A shift still open this many hours after check-in can be closed (work past midnight);
    // an older one was forgotten and no longer blocks the next day.
    'max_shift_hours' => (int) env('ATTENDANCE_MAX_SHIFT_HOURS', 20),

    // How far (meters) from the branch location a check-in still counts.
    'radius_meters' => (int) env('ATTENDANCE_RADIUS_METERS', 80),
];
