<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AbsenceMarker;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;

/** Marks absence without leave (black point). Runs at 3 PM for today; --from fills days that were missed. */
class AttendanceMarkAbsences extends Command
{
    protected $signature = 'attendance:mark-absences {--from= : أول يوم (Y-m-d)، الافتراضي النهارده} {--to= : آخر يوم، الافتراضي النهارده}';

    protected $description = 'Marks employees who did not clock in by 3 PM as absent (black point).';

    public function handle(): int
    {
        $to = Carbon::parse($this->option('to') ?: today());
        $from = Carbon::parse($this->option('from') ?: $to);
        $total = 0;

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $n = AbsenceMarker::markDay($day);
            $total += $n;
            $this->line($day->toDateString().': '.$n);
        }

        $this->info("Marked absent: {$total}");

        return self::SUCCESS;
    }
}
