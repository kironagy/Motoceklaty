<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryResource\Pages\EditDelivery;
use App\Models\Brand;
use App\Models\InstallmentRequest;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-11-26: every new bot request lands «متوقف»; the employee who holds it makes it
 * «طلب جديد» once they have checked it - and that is the only status change they can make.
 */
class BotIntakeHoldTest extends TestCase
{
    use RefreshDatabase;

    private function botRequest(Staff $staff, string $status = 'paused'): InstallmentRequest
    {
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $machine = Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 70000, 'installment_price' => 75000, 'is_active' => true]);
        InstallmentSystem::create(['name' => 'امان', 'plans' => [['months' => 24, 'interest' => 50]], 'administrative_fees' => 7, 'is_active' => true]);

        return InstallmentRequest::create([
            'status' => $status, 'request_type' => 'bot', 'bot_hold_at' => $status === 'paused' ? now() : null, 'staff_id' => $staff->id, 'installment_type' => 'امان', 'months' => 24,
            'machine_id' => $machine->id, 'machine_installment_price' => 75000, 'deposit' => 15000,
            'applicant_name' => 'محمد علي حسن', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29901011234567',
        ]);
    }

    public function test_an_employee_makes_a_new_bot_request_a_new_request(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $this->actingAs($staff, 'filament');
        $request = $this->botRequest($staff);
        $this->assertTrue($request->isBotIntakeHold());

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['status' => 'approved'])
            ->call('save');
        $this->assertSame('paused', $request->refresh()->status);

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['status' => 'new_request'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('new_request', $request->refresh()->status);
        $this->assertFalse($request->isBotIntakeHold());
    }

    public function test_once_it_is_a_new_request_the_employee_cannot_change_its_status(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $this->actingAs($staff, 'filament');
        $request = $this->botRequest($staff, 'new_request');

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['status' => 'paused'])
            ->call('save');
        $this->assertSame('new_request', $request->refresh()->status);
    }
}
