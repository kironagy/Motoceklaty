<?php

namespace App\Jobs;

use App\Domain\Teaching\RegressionRunner;
use App\Models\TeachingCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunTeachingRegression implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(RegressionRunner $runner): void
    {
        TeachingCase::where('is_active', true)->orderBy('id')->each(fn (TeachingCase $case) => $runner->run($case));
    }
}
