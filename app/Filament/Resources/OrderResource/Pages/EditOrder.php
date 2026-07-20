<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ما تحفظش الحقول الإضافية بتاعة الاستيراد/التصدير في orders
        unset($data['import_items'], $data['export_item_ids']);
        return $data;
    }
}

