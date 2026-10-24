<?php

namespace App\Domain\Inventory;

use App\Models\{Inventory, InventoryItem, Letter, Machine, Product, Warehouse};
use App\Filament\Resources\InventoryResource;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStock
{
    public const PIECE_FIELDS = ['chassis_image', 'engine_image', 'color', 'chassis_number', 'engine_number'];
    public const SALE_FIELDS = ['customer_name', 'customer_id_image', 'national_id', 'phone', 'address', 'cash', 'limit', 'admin_fees', 'paid', 'remaining', 'chassis_number', 'engine_number'];

    public function warehouses(): Builder
    {
        return Warehouse::query()->when(! Access::full(InventoryResource::class),
            fn (Builder $query) => $query->whereKey(Access::user()?->warehouse_id ?? 0));
    }

    public function available(int $machineId): Builder
    {
        return InventoryItem::query()->whereNull('sold_at')
            ->whereHas('inventory', fn (Builder $q) => $q->whereIn('warehouse_id', $this->warehouses()->select('id')))
            ->whereHas('inventory.product', fn (Builder $q) => $q->where('machine_id', $machineId));
    }

    public function modelQuery(): Builder
    {
        $counts = InventoryItem::query()->selectRaw('COUNT(*)')
            ->join('inventories', 'inventories.id', '=', 'inventory_items.inventory_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->whereIn('inventories.warehouse_id', $this->warehouses()->select('id'))
            ->whereColumn('products.machine_id', 'machines.id')->whereNull('inventory_items.sold_at');
        return Machine::query()->select('machines.*')->selectSub(
            $counts,
            'available_items_count'
        )->when(! Access::full(InventoryResource::class), fn (Builder $q) => $q->whereRaw('(' . $counts->toSql() . ') > 0', $counts->getBindings()));
    }

    public function formState(Machine $machine): array
    {
        $items = $this->available($machine->id)->with('inventory')->get()->groupBy('inventory.warehouse_id');
        return ['warehouses' => $this->warehouses()->orderBy('name')->get()->mapWithKeys(fn ($warehouse) => [
            $warehouse->id => ['items' => ($items->get($warehouse->id) ?? collect())->map(fn ($item) => [
                ...Arr::only($item->toArray(), self::PIECE_FIELDS), 'id' => $item->id,
                'version' => $this->version($item),
            ])->all()],
        ])->all()];
    }

    public function save(Machine $machine, array $state): void
    {
        \Illuminate\Support\Facades\Validator::make($state, [
            'warehouses' => ['required', 'array'], 'warehouses.*.items' => ['array'],
            'warehouses.*.items.*.color' => ['required', 'string', 'max:255'],
            'warehouses.*.items.*.chassis_number' => ['nullable', 'string', 'max:255'],
            'warehouses.*.items.*.engine_number' => ['nullable', 'string', 'max:255'],
        ])->validate();
        DB::transaction(function () use ($machine, $state) {
            Machine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $seen = [];
            foreach ($state['warehouses'] ?? [] as $warehouseId => $warehouseState) {
                if (! $this->warehouses()->whereKey($warehouseId)->exists()) {
                    throw ValidationException::withMessages(['data.warehouses' => 'أحد المعارض لم يعد موجودًا. حدّث الصفحة.']);
                }
                foreach ($warehouseState['items'] ?? [] as $row) {
                    $values = Arr::only($row, self::PIECE_FIELDS);
                    $values['chassis_image'] = $values['chassis_image'] ?? '';
                    $values['engine_image'] = $values['engine_image'] ?? '';
                    if (! empty($row['id'])) {
                        $id = (int) $row['id'];
                        $item = $this->available($machine->id)->whereHas('inventory', fn ($q) => $q->where('warehouse_id', $warehouseId))->lockForUpdate()->find($id);
                        if (! $item || isset($seen[$id]) || $this->version($item) !== ($row['version'] ?? null)) {
                            throw ValidationException::withMessages(['data.warehouses' => 'إحدى القطع اتباعت أو اتعدلت من مستخدم آخر. حدّث الصفحة قبل الحفظ.']);
                        }
                        $seen[$id] = true;
                        $item->update($values);
                    } else {
                        $product = Product::firstOrCreate(['machine_id' => $machine->id], ['name' => $machine->name]);
                        $inventory = Inventory::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId]);
                        InventoryItem::create(['inventory_id' => $inventory->id, ...$values,
                            'chassis_image' => $values['chassis_image'] ?? '', 'engine_image' => $values['engine_image'] ?? '']);
                    }
                }
            }
        });
    }

    public function sell(Machine $machine, array $data): Letter
    {
        \Illuminate\Support\Facades\Validator::make($data, [
            'warehouse_id' => ['required', 'integer'], 'inventory_item_id' => ['required', 'integer'],
            'customer_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:30'],
            'chassis_number' => ['required', 'string', 'max:255'], 'engine_number' => ['required', 'string', 'max:255'],
            ...collect(['cash', 'limit', 'admin_fees', 'paid', 'remaining'])->mapWithKeys(fn ($field) => [$field => ['nullable', 'numeric', 'min:0', 'max:99999999']])->all(),
        ])->validate();
        return DB::transaction(function () use ($machine, $data) {
            $item = $this->available($machine->id)->whereHas('inventory', fn ($q) => $q->where('warehouse_id', $data['warehouse_id']))
                ->with('inventory')->lockForUpdate()->find($data['inventory_item_id']);
            if (! $item) {
                throw ValidationException::withMessages(['inventory_item_id' => 'القطعة غير متاحة أو تم بيعها بالفعل. اختر قطعة أخرى.']);
            }
            $sale = Arr::only($data, self::SALE_FIELDS);
            $letter = Letter::create([
                'inventory_id' => $item->inventory_id, 'inventory_item_id' => $item->id,
                'warehouse_id' => $item->inventory->warehouse_id,
                ...Arr::only($item->toArray(), self::PIECE_FIELDS), ...$sale,
            ]);
            $item->fill($sale);
            $item->sold_at = now();
            $item->save();
            return $letter;
        });
    }

    private function version(InventoryItem $item): string
    {
        return hash('sha256', json_encode($item->only([...self::PIECE_FIELDS, 'inventory_id', 'updated_at', 'sold_at'])));
    }
}
