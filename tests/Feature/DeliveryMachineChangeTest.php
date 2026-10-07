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
 * Owner 2026-10-07: changing the machine of a request already saved
 * failed with "Column 'deposit' cannot be null" - staff deleted the request
 * and entered it again.
 */
class DeliveryMachineChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_machine_of_a_saved_request_can_be_changed(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_admin' => false, 'is_super_admin' => false]);
        $this->actingAs($staff, 'filament');

        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $old = Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 70000, 'installment_price' => 75000, 'is_active' => true]);
        $new = Machine::create(['name' => 'L250', 'brand_id' => $brand->id, 'cash_price' => 55000, 'installment_price' => 59000, 'is_active' => true]);
        InstallmentSystem::create(['name' => 'امان', 'plans' => [['months' => 24, 'interest' => 50]], 'administrative_fees' => 7, 'is_active' => true]);

        $request = InstallmentRequest::create([
            'status' => 'new', 'staff_id' => $staff->id, 'installment_type' => 'امان', 'months' => 24,
            'machine_id' => $old->id, 'machine_installment_price' => 75000, 'deposit' => 15000,
            'applicant_name' => 'حسن محمد', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29901011234567',
        ]);

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->assertFormSet(['deposit' => 15000])
            ->fillForm(['machine_id' => $new->id])
            ->assertFormSet(['machine_installment_price' => 59000])
            ->call('save')
            ->assertHasNoFormErrors();

        $request->refresh();
        $this->assertSame($new->id, (int) $request->machine_id);
        $this->assertEquals(59000, (float) $request->machine_installment_price);
        $this->assertEquals(15000, (float) $request->deposit);
    }

    /** A phone keyboard typing ١٥٬٠٠٠ in a number box sent nothing - the save crashed on deposit = null. */
    public function test_prices_typed_in_arabic_digits_are_saved(): void
    {
        [$request, $new] = $this->request();

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['machine_id' => $new->id, 'machine_installment_price' => '٦٠٬٠٠٠', 'deposit' => '١٠٠٠٠'])
            ->call('save')
            ->assertHasNoFormErrors();

        $request->refresh();
        $this->assertSame($new->id, (int) $request->machine_id);
        $this->assertEquals(60000, (float) $request->machine_installment_price);
        $this->assertEquals(10000, (float) $request->deposit);
    }

    public function test_an_empty_deposit_is_a_form_error_not_a_crash(): void
    {
        [$request, $new] = $this->request();

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['machine_id' => $new->id, 'deposit' => null])
            ->call('save')
            ->assertHasFormErrors(['deposit']);

        $this->assertEquals(15000, (float) $request->fresh()->deposit);
    }

    /** @return array{0: InstallmentRequest, 1: Machine} */
    private function request(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $this->actingAs($staff, 'filament');

        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $old = Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 70000, 'installment_price' => 75000, 'is_active' => true]);
        $new = Machine::create(['name' => 'L250', 'brand_id' => $brand->id, 'cash_price' => 55000, 'installment_price' => 59000, 'is_active' => true]);
        InstallmentSystem::create(['name' => 'امان', 'plans' => [['months' => 24, 'interest' => 50]], 'administrative_fees' => 7, 'is_active' => true]);

        $request = InstallmentRequest::create([
            'status' => 'new', 'staff_id' => $staff->id, 'installment_type' => 'امان', 'months' => 24,
            'machine_id' => $old->id, 'machine_installment_price' => 75000, 'deposit' => 15000,
            'applicant_name' => 'حسن محمد', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29901011234567',
        ]);

        return [$request, $new];
    }
}
