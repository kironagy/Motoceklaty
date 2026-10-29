<?php

namespace App\Domain\Catalog;

use App\Support\ColorNamer;
use Illuminate\Support\Facades\DB;

/** Physical unsold pieces across all warehouses, independent of dashboard-user access. */
class CashInventoryService
{
    public function forMachine(int $machineId): array
    {
        $rows = DB::table('inventory_items as piece')
            ->join('inventories as inventory', 'inventory.id', '=', 'piece.inventory_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->where('product.machine_id', $machineId)->whereNull('piece.sold_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('letters as sale')->whereColumn('sale.inventory_item_id', 'piece.id'))
            ->select(['warehouse.id as warehouse_id', 'warehouse.name as warehouse_name', 'warehouse.branch_id', 'branch.name as branch_name', 'branch.address', 'branch.map_url', 'branch.is_active as branch_active', 'piece.color'])
            ->orderBy('warehouse.id')->get();

        $locations = $rows->groupBy('warehouse_id')->map(function ($pieces) {
            $location = $pieces->first();
            $colors = $pieces->groupBy(fn ($piece) => trim((string) $piece->color))
                ->map(fn ($pieces, $color) => ['color' => $color === '' ? null : ColorNamer::name((string) $color), 'quantity' => $pieces->count()])->values()->all();
            return [
                'warehouse_id' => (int) $location->warehouse_id,
                'warehouse_name' => $location->warehouse_name,
                'branch_id' => $location->branch_id === null ? null : (int) $location->branch_id,
                'name' => $location->branch_name ?: $location->warehouse_name,
                'address' => $location->branch_active ? $location->address : null,
                'map_url' => $location->branch_active ? $location->map_url : null,
                'pickup_location_confirmed' => (bool) $location->branch_active,
                'quantity' => $pieces->count(), 'colors' => $colors,
            ];
        })->values()->all();

        return ['machine_id' => $machineId, 'source' => 'physical_inventory', 'checked_at' => now()->toIso8601String(),
            'available' => $rows->isNotEmpty(), 'total_quantity' => $rows->count(), 'branches' => $locations,
            'reservation_created' => false];
    }
}
