<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyTaskResource;
use App\Filament\Resources\DeliveryResource;
use App\Filament\Resources\DeliveryResource\Pages\ListDeliveries;
use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Filament\Widgets\RequestsByTypeOverview;
use App\Models\{Brand, DailyTask, InstallmentRequest, InstallmentSystem, Inventory, InventoryItem, Machine, Product, Staff, Team, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner requests 2026-11-09: sales by employees, Aman staff vs fake requests, bot duplicates, dashboard, daily tasks. */
class StaffAccessRulesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $attributes = []): Staff
    {
        $staff = Staff::create(['name' => 'S'.uniqid(), 'email' => uniqid().'@x.test', 'password' => 'secret', ...$attributes]);
        $staff->forceFill(array_intersect_key($attributes, array_flip(['is_hitler', 'is_admin', 'is_super_admin', 'is_company_employee'])))->save();

        return $staff->fresh();
    }

    private function machine(): Machine
    {
        $brand = Brand::create(['name' => 'B'.uniqid(), 'image' => 'x.png']);

        return Machine::create(['name' => 'Model '.uniqid(), 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true]);
    }

    private function request(string $type, string $nid, string $installmentType = 'امان', string $status = 'new_request'): InstallmentRequest
    {
        $request = InstallmentRequest::create([
            'status' => $status, 'installment_type' => $installmentType, 'months' => 12,
            'machine_id' => $this->machine()->id, 'machine_installment_price' => 55000, 'deposit' => 0,
            'applicant_name' => 'عميل', 'applicant_phone' => '010'.random_int(10000000, 99999999), 'applicant_national_id' => $nid,
        ]);
        $request->forceFill(['request_type' => $type, 'status' => $status])->save();

        return $request;
    }

    public function test_employee_who_only_views_inventory_can_sell_and_the_sale_is_under_his_name(): void
    {
        $machine = $this->machine();
        $warehouse = Warehouse::create(['name' => 'Showroom']);
        $inventory = Inventory::create(['product_id' => Product::create(['name' => 'p', 'machine_id' => $machine->id])->id, 'warehouse_id' => $warehouse->id]);
        $piece = InventoryItem::create(['inventory_id' => $inventory->id, 'chassis_image' => '', 'engine_image' => '', 'chassis_number' => 'CH', 'engine_number' => 'EN', 'color' => 'Red']);
        $employee = $this->staff(['warehouse_id' => $warehouse->id, 'permissions' => ['InventoryResource' => ['view'], 'LetterResource' => ['view']]]);
        $this->actingAs($employee, 'filament');

        Livewire::test(ListInventories::class)
            ->assertTableActionVisible('sell', $machine)
            ->callTableAction('sell', $machine, data: [
                'warehouse_id' => $warehouse->id, 'inventory_item_id' => $piece->id, 'customer_name' => 'Customer',
                'phone' => '01000000000', 'chassis_number' => 'CH', 'engine_number' => 'EN', 'paid' => 100,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('letters', ['inventory_item_id' => $piece->id, 'sold_by' => $employee->id]);
        $this->assertSame($employee->id, (int) $piece->fresh()->sold_by);
        $this->assertNotNull($piece->fresh()->sold_at);
    }

    public function test_aman_employee_never_sees_fake_requests(): void
    {
        $system = InstallmentSystem::create(['name' => 'امان']);
        $normal = $this->request('normal', '29901011234561');
        $fake = $this->request('fake', '29901011234562');
        $aman = $this->staff(['is_company_employee' => true, 'is_admin' => true, 'installment_system_id' => $system->id]);
        $aman->installmentSystems()->attach($system->id);
        $this->actingAs($aman, 'filament');

        $ids = DeliveryResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($fake->id, $ids);
        $this->assertArrayNotHasKey('fake', Livewire::test(ListDeliveries::class)->instance()->getTabs());

        $this->actingAs($this->staff(['is_hitler' => true, 'is_admin' => true]), 'filament');
        $this->assertContains($fake->id, DeliveryResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_national_id_of_a_bot_request_cannot_be_filed_as_normal_or_fake(): void
    {
        $bot = $this->request('bot', '29901011234563');
        $this->actingAs($this->staff(['is_hitler' => true]), 'filament');

        foreach (['normal', 'fake'] as $tab) {
            Livewire::test(ListDeliveries::class, ['activeTab' => $tab])
                ->callAction('new_installment_request', ['applicant_national_id' => '29901011234563'])
                ->assertNotified('العميل مقدم قبل كده في طلبات البوت')
                ->assertNoRedirect();
        }

        $this->assertSame($bot->id, DeliveryResource::botRequestFor('٢٩٩٠١٠١١٢٣٤٥٦٣')?->id);
        $this->assertNull(DeliveryResource::botRequestFor('29901011234569'));

        Livewire::test(ListDeliveries::class, ['activeTab' => 'normal'])
            ->callAction('new_installment_request', ['applicant_national_id' => '29901011234569'])
            ->assertRedirect();
    }

    public function test_request_status_widget_is_for_admin_super_admin_and_owner_only(): void
    {
        foreach ([['is_hitler' => true], ['is_super_admin' => true], ['is_admin' => true]] as $flags) {
            $this->actingAs($this->staff($flags), 'filament');
            $this->assertTrue(RequestsByTypeOverview::canView());
        }

        foreach ([[], ['is_company_employee' => true, 'is_admin' => true]] as $flags) {
            $this->actingAs($this->staff($flags), 'filament');
            $this->assertFalse(RequestsByTypeOverview::canView());
        }

        $this->request('normal', '29901011234564', 'x', 'pending');
        $this->request('bot', '29901011234565', 'x', 'new');
        $this->request('bot', '29901011234566', 'x', 'new_request');
        $this->actingAs($this->staff(['is_hitler' => true]), 'filament');

        Livewire::test(RequestsByTypeOverview::class)->assertSee('الطلبات الأساسية')->assertSee('طلبات البوت')->assertSee('تحت الاستعلام')
            ->assertViewHas('types', fn ($types) => $types['normal']['rows']['pending']['count'] === 1 && $types['bot']['rows']['new']['count'] === 2);
    }

    public function test_employee_sees_only_his_tasks_and_leader_only_his_team_even_with_full_role_access(): void
    {
        $role = ['DailyTaskResource' => ['view', 'create', 'show', 'update', 'delete', 'all']];
        $leader = $this->staff(['permissions' => $role]);
        $member = $this->staff(['permissions' => $role]);
        $other = $this->staff(['permissions' => $role]);
        $team = Team::create(['name' => 'T1', 'leader_id' => $leader->id]);
        $team->members()->attach([$member->id]);
        $otherTeam = Team::create(['name' => 'T2', 'leader_id' => $other->id]);
        $otherTeam->members()->attach([$other->id]);

        $task = fn (Team $t, Staff $s) => DailyTask::create(['team_id' => $t->id, 'assigned_to' => $s->id, 'created_by' => $t->leader_id, 'title' => 't', 'task_date' => today(), 'status' => DailyTask::TODO]);
        $mine = $task($team, $member);
        $leaders = $task($team, $leader);
        $foreign = $task($otherTeam, $other);

        $this->actingAs($member, 'filament');
        $this->assertSame([$mine->id], DailyTaskResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($leader, 'filament');
        $this->assertEqualsCanonicalizing([$mine->id, $leaders->id], DailyTaskResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($this->staff(['is_hitler' => true]), 'filament');
        $this->assertEqualsCanonicalizing([$mine->id, $leaders->id, $foreign->id], DailyTaskResource::getEloquentQuery()->pluck('id')->all());
    }
}
