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
    protected $signature = 'requests:sync-bot {--rebuild : rebuild the address parts, work details and notes of requests staff have not touched yet} {--id=* : only these request ids}';

    protected $description = 'Mark bot-submitted installment requests as bot requests and fill their empty customer data';

    public function handle(LegacyRequestProjector $projector): int
    {
        $count = 0;

        $rebuild = (bool) $this->option('rebuild');

        InstallmentRequest::withTrashed()
            ->whereNotNull('application_id')
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->with('application')
            ->chunkById(100, function ($requests) use ($projector, &$count, $rebuild) {
                foreach ($requests as $request) {
                    // a request staff already worked on keeps what they wrote
                    $untouched = $request->status_updated_by === null && $request->staff_id === null;
                    $projector->refresh($request, $rebuild && $untouched ? LegacyRequestProjector::BOT_TEXT_COLUMNS : []);
                    $count++;
                }
            });

        $this->info("Synced {$count} bot request(s).");

        return self::SUCCESS;
    }
}
