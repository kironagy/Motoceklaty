<?php

namespace App\Jobs;

use App\Domain\Teaching\TeachingCoach;
use App\Models\TeachingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Finishes a correction that arrived while the AI was out of requests. */
class ResumeTeachingSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $sessionId)
    {
    }

    public function handle(TeachingCoach $coach): void
    {
        if ($session = TeachingSession::find($this->sessionId)) {
            $coach->resume($session);
        }
    }
}
