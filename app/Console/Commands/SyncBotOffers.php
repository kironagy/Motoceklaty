<?php

namespace App\Console\Commands;

use App\Domain\Offers\OfferMachineSync;
use Illuminate\Console\Command;

/** Owner 2026-11-19: a machine offer's catalog row follows its dates and stock (عروض البوت). */
class SyncBotOffers extends Command
{
    protected $signature = 'bot-offers:sync';

    protected $description = 'Turn machine offers on/off in the catalog as their dates and stock change';

    public function handle(OfferMachineSync $sync): int
    {
        $this->info('changed: '.$sync->syncAll());

        return self::SUCCESS;
    }
}
