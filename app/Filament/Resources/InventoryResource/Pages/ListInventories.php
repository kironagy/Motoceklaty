<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Models\InventoryItem;
use Filament\Resources\Pages\ListRecords;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;
    protected function getHeaderActions(): array { return []; }

    public function getSubheading(): ?string
    {
        $legacy = InventoryItem::whereHas('inventory', fn ($q) => $q->whereIn('warehouse_id', app(\App\Domain\Inventory\InventoryStock::class)->warehouses()->select('id')))->whereNull('sold_at')->whereHas('inventory.product', fn ($q) => $q->whereNull('machine_id'))->count();
        return $legacy ? "يوجد {$legacy} قطعة في سجلات المخزون القديم محفوظة كما هي، وغير محسوبة ضمن موديلات الكتالوج لعدم تحديد الموديل الصحيح لها." : 'المكن هو المصدر الوحيد لأسماء الموديلات. أي موديل جديد يظهر هنا تلقائيًا.';
    }
}
