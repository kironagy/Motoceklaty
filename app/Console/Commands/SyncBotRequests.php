<?php

namespace App\Console\Commands;

use App\Domain\Applications\LegacyRequestProjector;
use App\Models\InstallmentRequest;
use Illuminate\Console\Command;

/**
 * Backfill for bot requests submitted before they were marked as
 * request_type=bot: moves them to the bot tab of the deliveries table and
 * fills the customer data columns that are still empty.
 */
class SyncBotRequests extends Command
{
    protected $signature = 'requests:sync-bot';

    protected $description = 'Mark bot-submitted installment requests as bot requests and fill their empty customer data';

    public function handle(LegacyRequestProjector $projector): int
    {
        $count = 0;

        InstallmentRequest::withTrashed()
            ->whereNotNull('application_id')
            ->with('application')
            ->chunkById(100, function ($requests) use ($projector, &$count) {
                foreach ($requests as $request) {
                    $projector->refresh($request);
                    $count++;
                }
            });

        $this->info("Synced {$count} bot request(s).");

        return self::SUCCESS;
    }
}
