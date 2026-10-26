<?php

namespace App\Console\Commands;

use App\Support\GuideHistory;
use Illuminate\Console\Command;

class ImportGuideHistory extends Command
{
    protected $signature = 'guide:import-history';
    protected $description = 'نسخ سجلات الحركة القديمة للمرشد بدون حذفها أو تكرار المستورد منها';

    public function handle(GuideHistory $history): int
    {
        foreach ($history->import() as $source => $count) $this->info($source.': '.$count.' imported');
        return self::SUCCESS;
    }
}
