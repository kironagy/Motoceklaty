<?php

namespace App\Console\Commands;

use App\Domain\Asfoora\AsfooraWatcher;
use Illuminate\Console\Command;

/** Owner 2026-11-26: runs the «عصفورة» watches that are due (every minute from the scheduler). */
class AsfooraWatches extends Command
{
    protected $signature = 'asfoora:watches';

    protected $description = 'Run the due «عصفورة» watches (new requests every hour ...)';

    public function handle(AsfooraWatcher $watcher): int
    {
        $this->line('ran '.$watcher->runDue());

        return self::SUCCESS;
    }
}
