<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Support\Merchants\MerchantTenancy;
use Illuminate\Console\Command;

/** After a deploy brings new tables or columns, copies them into every merchant's database. */
class MerchantsSync extends Command
{
    protected $signature = 'merchants:sync';

    protected $description = 'Bring every merchant database up to the main schema and refresh its login pages';

    public function handle(): int
    {
        foreach (Merchant::query()->orderBy('id')->get() as $merchant) {
            MerchantTenancy::provision($merchant);
            $this->line("✓ {$merchant->subdomain}");
        }

        return self::SUCCESS;
    }
}
