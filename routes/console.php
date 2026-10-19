<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gemini:reset-usage')
    ->dailyAt('00:05')
    ->timezone(config('gemini.rate_limits.daily_reset_timezone', 'America/Los_Angeles'));

Schedule::call(fn () => app(\App\Services\GeminiAlertService::class)->repeatOpenAlerts())
    ->everyMinute()
    ->name('gemini-alerts-repeat')
    ->withoutOverlapping();


// Handoffs no staff member answered in time go back to the bot.
Schedule::call(fn () => app(\App\Domain\Handoff\HandoffService::class)->returnUnansweredToAgent())
    ->everyMinute()
    ->name('handoff-return-unanswered')
    ->withoutOverlapping();

// Reminds a customer who went quiet half way through an application (off
// until AGENT_APPLICATION_NUDGE_AFTER_MINUTES is set).
Schedule::call(fn () => app(\App\Domain\Applications\ApplicationNudgeService::class)->nudgeStalled())
    ->everyFiveMinutes()
    ->name('application-nudges')
    ->withoutOverlapping();

// Dynamic Links: expires old links and follows up a customer who has not sent his form.
Schedule::call(fn () => app(\App\Domain\Forms\FormLinkFollowUpService::class)->run())
    ->everyMinute()
    ->name('form-link-followups')
    ->withoutOverlapping();

// A correction whose background run was cut off (deploy, restart) is picked up again.
Schedule::call(fn () => app(\App\Domain\Teaching\TeachingCoach::class)->recoverStuck())
    ->everyFiveMinutes()
    ->name('teaching-recover-stuck')
    ->withoutOverlapping();

// Attendance: whoever hasn't clocked in by 3 PM (and isn't on vacation or a day off) is absent - black
// point. The 23:50 run catches a day the 3 PM run missed.
Schedule::command('attendance:mark-absences')->dailyAt('15:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('attendance:mark-absences')->dailyAt('23:50')->timezone('Africa/Cairo')->withoutOverlapping();
