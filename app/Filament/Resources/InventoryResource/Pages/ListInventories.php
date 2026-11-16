<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Domain\Inventory\InventoryStock;
use App\Filament\Resources\InventoryResource;
use App\Models\{InventoryItem, InventoryTransfer, Machine, Warehouse};
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\{Select, Textarea};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->transferAction(), $this->transfersLogAction()];
    }

    /** Owner 2026-11-16: any admin moves a machine from one showroom to another. */
    private function transferAction(): Action
    {
        $stock = app(InventoryStock::class);

        return Action::make('transfer')->label('تحويل مكنة لمعرض تاني')->icon('heroicon-o-arrows-right-left')->color('warning')
            ->visible(fn () => InventoryStock::canTransfer())
            ->modalHeading('تحويل مكنة من معرض لمعرض')->modalWidth('2xl')->modalSubmitActionLabel('تأكيد التحويل')
            ->form([
                Select::make('machine_id')->label('الموديل')->required()->searchable()->live()
                    ->options(fn () => Machine::query()->whereIn('id', InventoryItem::query()->whereNull('sold_at')
                        ->join('inventories', 'inventories.id', '=', 'inventory_items.inventory_id')
                        ->join('products', 'products.id', '=', 'inventories.product_id')->select('products.machine_id'))->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(function (Forms\Set $set) { $set('from_warehouse_id', null); $set('inventory_item_id', null); }),
                Select::make('from_warehouse_id')->label('من معرض')->required()->searchable()->live()
                    ->options(fn (Forms\Get $get) => $get('machine_id') ? Warehouse::query()->whereIn('id',
                        $stock->transferable((int) $get('machine_id'))->join('inventories', 'inventories.id', '=', 'inventory_items.inventory_id')->select('inventories.warehouse_id'))
                        ->orderBy('name')->pluck('name', 'id') : [])
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('inventory_item_id', null)),
                Select::make('inventory_item_id')->label('المكنة • الشاسيه / الماتور / اللون')->required()->searchable()
                    ->options(fn (Forms\Get $get) => $get('machine_id') && $get('from_warehouse_id')
                        ? $stock->transferable((int) $get('machine_id'))->whereHas('inventory', fn ($q) => $q->where('warehouse_id', $get('from_warehouse_id')))->get()
                            ->mapWithKeys(fn (InventoryItem $item) => [$item->id => 'شاسيه: '.($item->chassis_number ?: 'غير مسجل').' • ماتور: '.($item->engine_number ?: 'غير مسجل').' • '.$item->color.' • #'.$item->id])
                        : []),
                Select::make('to_warehouse_id')->label('إلى معرض')->required()->searchable()
                    ->options(fn (Forms\Get $get) => Warehouse::query()->when($get('from_warehouse_id'), fn ($q, $from) => $q->whereKeyNot($from))->orderBy('name')->pluck('name', 'id')),
                Textarea::make('note')->label('ملاحظة')->maxLength(1000)->rows(2),
            ])
            ->action(function (array $data) use ($stock) {
                $transfer = $stock->transfer($data);
                Notification::make()->success()->title('المكنة اتحولت')
                    ->body(($transfer->from?->name ?? '-').' ← '.($transfer->to?->name ?? '-'))->send();
            });
    }

    private function transfersLogAction(): Action
    {
        return Action::make('transfersLog')->label('سجل التحويلات')->icon('heroicon-o-clock')->color('gray')
            ->visible(fn () => InventoryStock::canTransfer())
            ->modalHeading('آخر التحويلات بين المعارض')->modalWidth('5xl')
            ->modalSubmitAction(false)->modalCancelActionLabel('قفل')
            ->modalContent(fn () => view('filament.resources.inventory.transfers', [
                'transfers' => InventoryTransfer::with(['item', 'machine', 'from', 'to', 'staff'])->latest('id')->take(100)->get(),
            ]));
    }

    public function getSubheading(): ?string
    {
        $legacy = InventoryItem::whereHas('inventory', fn ($q) => $q->whereIn('warehouse_id', app(\App\Domain\Inventory\InventoryStock::class)->warehouses()->select('id')))->whereNull('sold_at')->whereHas('inventory.product', fn ($q) => $q->whereNull('machine_id'))->count();
        return $legacy ? "يوجد {$legacy} قطعة في سجلات المخزون القديم محفوظة كما هي، وغير محسوبة ضمن موديلات الكتالوج لعدم تحديد الموديل الصحيح لها." : 'المكن هو المصدر الوحيد لأسماء الموديلات. أي موديل جديد يظهر هنا تلقائيًا.';
    }
}
