<?php

namespace Tests\Feature;

use App\Domain\Inventory\InventoryStock;
use App\Filament\Resources\InventoryResource\Pages\{EditInventory, ListInventories};
use App\Models\{Brand, Inventory, InventoryItem, Letter, Machine, Product, Staff, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private Machine $machine;
    private Warehouse $warehouse;
    private InventoryItem $piece;
    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'Test Brand', 'image' => 'brand.png']);
        $this->machine = Machine::create(['name' => 'Model A', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000]);
        $this->warehouse = Warehouse::create(['name' => 'Showroom A']);
        $product = Product::create(['name' => 'Old display name', 'machine_id' => $this->machine->id]);
        $inventory = Inventory::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id]);
        $this->piece = InventoryItem::create(['inventory_id' => $inventory->id, 'chassis_image' => '', 'engine_image' => '', 'chassis_number' => 'CH-1', 'engine_number' => 'EN-1', 'color' => 'Black']);
        $this->stock = app(InventoryStock::class);
        $owner = Staff::create(['name' => 'Test Owner', 'email' => 'inventory-test@example.test', 'password' => 'test-password', 'is_hitler' => true]);
        $this->actingAs($owner, 'filament');
    }

    public function test_every_catalog_model_appears_without_creating_products_or_inventory_rows(): void
    {
        $new = Machine::create(['name' => 'New Model', 'brand_id' => $this->machine->brand_id, 'cash_price' => 1, 'installment_price' => 1]);
        Livewire::test(ListInventories::class)->assertCanSeeTableRecords([$this->machine, $new])->assertSee('New Model');
        $this->assertSame(1, (int) $this->stock->modelQuery()->find($this->machine->id)->available_items_count);
        $this->assertSame(0, (int) $this->stock->modelQuery()->find($new->id)->available_items_count);
        $this->assertDatabaseMissing('products', ['machine_id' => $new->id]);
    }

    public function test_edit_shows_all_showrooms_and_saves_pieces_without_deleting_existing_stock(): void
    {
        $other = Warehouse::create(['name' => 'Showroom B']);
        Livewire::test(EditInventory::class, ['record' => $this->machine->id])->assertSuccessful()->assertSee('Showroom A')->assertSee('Showroom B');
        $editor = Livewire::test(EditInventory::class, ['record' => $this->machine->id]);
        $state = $editor->get('data');
        $key = array_key_first($state['warehouses'][$this->warehouse->id]['items']);
        $editor->set("data.warehouses.{$this->warehouse->id}.items.{$key}.color", 'Blue')->call('save')->assertHasNoFormErrors();
        $state = $this->stock->formState($this->machine);
        $state['warehouses'][$other->id]['items'][] = ['chassis_number' => 'CH-2', 'engine_number' => 'EN-2', 'color' => 'Red'];
        $this->stock->save($this->machine, $state);
        $this->assertDatabaseHas('inventory_items', ['id' => $this->piece->id, 'color' => 'Blue']);
        $this->assertSame(2, $this->stock->available($this->machine->id)->count());
    }

    public function test_sale_creates_letter_and_marks_piece_sold_without_deletion_or_duplicate_sale(): void
    {
        $data = $this->saleData();
        $letter = $this->stock->sell($this->machine, $data);
        $this->assertSame($this->piece->id, $letter->inventory_item_id);
        $this->assertSame($this->warehouse->id, $letter->warehouse_id);
        $this->assertSame('Test Customer', $letter->customer_name);
        $this->assertSame('1000.00', $letter->paid);
        $this->assertSame('CH-1', $letter->chassis_number);
        $this->assertNotNull($this->piece->fresh()->sold_at);
        $this->assertSame(0, $this->stock->available($this->machine->id)->count());
        try { $this->stock->sell($this->machine, $data); $this->fail('Double sale was allowed'); }
        catch (ValidationException) { $this->assertSame(1, Letter::count()); }
    }

    public function test_stale_edit_cannot_restore_or_overwrite_a_sold_piece(): void
    {
        $state = $this->stock->formState($this->machine);
        $this->stock->sell($this->machine, $this->saleData());
        $this->expectException(ValidationException::class);
        $this->stock->save($this->machine, $state);
    }

    public function test_piece_from_a_different_model_or_warehouse_cannot_be_sold(): void
    {
        $other = Warehouse::create(['name' => 'Other']);
        $data = $this->saleData(); $data['warehouse_id'] = $other->id;
        try { $this->stock->sell($this->machine, $data); $this->fail('Wrong showroom accepted'); }
        catch (ValidationException) { $this->assertSame(0, Letter::count()); }
        $new = Machine::create(['name' => 'Other Model', 'brand_id' => $this->machine->brand_id, 'cash_price' => 1, 'installment_price' => 1]);
        $this->expectException(ValidationException::class);
        $this->stock->sell($new, $this->saleData());
    }

    public function test_negative_payments_are_rejected(): void
    {
        $data = $this->saleData(); $data['paid'] = -1;
        $this->expectException(ValidationException::class);
        $this->stock->sell($this->machine, $data);
    }

    public function test_edit_requires_inventory_update_permission(): void
    {
        $viewer = Staff::create(['name' => 'Viewer', 'email' => 'inventory-viewer@example.test', 'password' => 'test-password', 'warehouse_id' => $this->warehouse->id, 'permissions' => ['InventoryResource' => ['view']]]);
        $this->actingAs($viewer, 'filament');
        Livewire::test(EditInventory::class, ['record' => $this->machine->id])->assertForbidden();
    }

    public function test_sale_wizard_loads_for_available_model(): void
    {
        Livewire::test(ListInventories::class)->mountTableAction('sell', $this->machine)->assertHasNoTableActionErrors()->assertSee('اختيار القطعة');
    }

    public function test_sale_action_submits_the_customer_and_payment_fields_to_letters(): void
    {
        Livewire::test(ListInventories::class)->callTableAction('sell', $this->machine, data: $this->saleData())->assertHasNoTableActionErrors();
        $this->assertDatabaseHas('letters', ['inventory_item_id' => $this->piece->id, 'customer_name' => 'Test Customer', 'paid' => 1000]);
        $this->assertNotNull($this->piece->fresh()->sold_at);
    }
    private function saleData(): array
    {
        return ['warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $this->piece->id, 'customer_name' => 'Test Customer', 'phone' => '01000000000', 'chassis_number' => 'CH-1', 'engine_number' => 'EN-1', 'cash' => 50000, 'limit' => 0, 'admin_fees' => 100, 'paid' => 1000, 'remaining' => 49100];
    }

    public function test_points_summary_combines_four_points_and_keeps_history(): void
    {
        $staff = auth('filament')->user();
        $points = collect(range(1, 4))->map(fn () => \App\Models\AttendancePoint::create([
            'staff_id' => $staff->id, 'color' => 'green', 'amount' => 0, 'earned_on' => today(), 'status' => 'active', 'source' => 'manual',
        ]));
        $page = Livewire::test(\App\Filament\Resources\AttendancePointResource\Pages\ListAttendancePoints::class);
        $page->assertCountTableRecords(1)->assertTableColumnStateSet('green_total', 4, $page->instance()->getTableRecords()->first());
        $page->set('activeTab', 'history')->assertCountTableRecords(4);
        $this->assertDatabaseCount('attendance_points', 4);
    }

    public function test_remaining_report_download_and_access(): void
    {
        $this->stock->sell($this->machine, $this->saleData());
        Livewire::test(\App\Filament\Resources\LetterResource\Pages\ListLetters::class)
            ->callAction('remaining_report')->assertFileDownloaded('letters-remaining-' . now()->format('Y-m-d') . '.csv');
        $employee = Staff::create(['name' => 'Report viewer', 'email' => 'report-viewer@example.test', 'password' => 'test-password', 'permissions' => ['LetterResource' => ['view']]]);
        $this->actingAs($employee, 'filament');
        Livewire::test(\App\Filament\Resources\LetterResource\Pages\ListLetters::class)->assertActionHidden('remaining_report');
    }

    public function test_employee_only_sees_own_showroom_and_cannot_forge_other_showroom_sale_or_edit(): void
    {
        $other = Warehouse::create(['name' => 'Hidden Showroom']);
        $otherModel = Machine::create(['name' => 'Hidden Model', 'brand_id' => $this->machine->brand_id, 'cash_price' => 1, 'installment_price' => 1]);
        $product = Product::create(['name' => 'Hidden', 'machine_id' => $otherModel->id]);
        $inventory = Inventory::create(['warehouse_id' => $other->id, 'product_id' => $product->id]);
        $foreignPiece = InventoryItem::create(['inventory_id' => $inventory->id, 'chassis_image' => '', 'engine_image' => '', 'color' => 'Red']);
        $employee = Staff::create(['name' => 'Employee', 'email' => 'employee@example.test', 'password' => 'test-password', 'warehouse_id' => $this->warehouse->id, 'permissions' => ['InventoryResource' => ['view', 'update'], 'LetterResource' => ['create']]]);
        $this->actingAs($employee, 'filament');
        Livewire::test(ListInventories::class)->assertCanSeeTableRecords([$this->machine])->assertCanNotSeeTableRecords([$otherModel]);
        Livewire::test(EditInventory::class, ['record' => $this->machine->id])->assertSee('Showroom A')->assertDontSee('Hidden Showroom');
        $this->assertSame(1, (int) $this->stock->modelQuery()->find($this->machine->id)->available_items_count);
        $this->assertSame(0, $this->stock->available($otherModel->id)->count());
        $this->assertSame([$this->warehouse->id], $this->stock->warehouses()->pluck('id')->all());
        $data = $this->saleData(); $data['warehouse_id'] = $other->id; $data['inventory_item_id'] = $foreignPiece->id;
        try { $this->stock->sell($otherModel, $data); $this->fail('Foreign showroom sale accepted'); }
        catch (ValidationException) { $this->assertDatabaseCount('letters', 0); }
        try { $this->stock->save($this->machine, ['warehouses' => [$other->id => ['items' => [['color' => 'Blue']]]]]); $this->fail('Foreign showroom edit accepted'); }
        catch (ValidationException) { $this->assertDatabaseCount('inventory_items', 2); }
        $employee->update(['warehouse_id' => null]);
        $this->assertSame(0, $this->stock->modelQuery()->count());
        $this->assertSame(0, $this->stock->warehouses()->count());
        $employee->update(['permissions' => ['InventoryResource' => ['view', 'update', 'all']]]);
        $this->assertSame(2, $this->stock->warehouses()->count());
        $this->assertSame(2, $this->stock->modelQuery()->count());
    }
}
