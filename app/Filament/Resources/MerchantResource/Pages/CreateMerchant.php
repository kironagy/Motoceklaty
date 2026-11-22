<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Support\Merchants\MerchantTenancy;
use Filament\Resources\Pages\CreateRecord;

class CreateMerchant extends CreateRecord
{
    protected static string $resource = MerchantResource::class;

    /** Builds the showroom's own database and its login. */
    protected function afterCreate(): void
    {
        MerchantTenancy::provision($this->record, $this->data['password'] ?? null);
    }
}
