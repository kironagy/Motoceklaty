<?php

namespace App\Jobs;

use App\Domain\Teaching\TeachingCoach;
use App\Models\TeachingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An owner's correction, worked out in the background. Run inside the page
 * request it hit the gateway timeout and the session never finished.
 */
class TeachCorrection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;


    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $sessionId)
    {
        // long runs: its own worker (config/queue.php "teaching")
        $this->onConnection('teaching')->onQueue('teaching');
    }

    public function handle(TeachingCoach $coach): void
    {
        if ($session = TeachingSession::find($this->sessionId)) {
            $coach->run($session);
        }
    }
}
