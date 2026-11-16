<?php

namespace App\Console\Commands;

use App\Models\AmanToken;
use App\Models\Staff;
use Illuminate\Console\Command;

class AmanExtensionToken extends Command
{
    protected $signature = 'aman:token {staff : staff id or email} {--revoke : sign this employee out of every device}';

    protected $description = 'Create an Aman uploader extension key for one employee (or sign them out everywhere)';

    public function handle(): int
    {
        $arg = $this->argument('staff');
        $staff = Staff::where('id', $arg)->orWhere('email', $arg)->first();

        if (! $staff) {
            $this->error('Staff not found: '.$arg);

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $n = AmanToken::where('staff_id', $staff->id)->delete();
            $this->info("Signed {$staff->name} out of {$n} device(s)");

            return self::SUCCESS;
        }

        $this->info('Key for '.$staff->name.' (shown once):');
        $this->line(AmanToken::issue($staff, 'artisan'));

        return self::SUCCESS;
    }
}
