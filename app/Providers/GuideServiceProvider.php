<?php

namespace App\Providers;

use App\Support\GuideRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class GuideServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GuideRecorder::class);
        $this->app->scoped(\App\Support\GuideMutations::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\DB::listen(fn ($query) => app(\App\Support\GuideMutations::class)->collect($query));
        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            Event::listen('eloquent.'.$event.': *', function (string $name, array $payload) use ($event) {
                app(GuideRecorder::class)->model($event, $payload[0]);
            });
        }
        Event::listen(Login::class, fn (Login $event) => app(GuideRecorder::class)->write(
            'login', 'جلسة الدخول', 'سجّل الدخول', actor: $event->user, context: ['guard' => $event->guard],
        ));
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                app(GuideRecorder::class)->write('logout', 'جلسة الدخول', 'سجّل الخروج', actor: $event->user, context: ['guard' => $event->guard]);
            }
        });
    }
}
