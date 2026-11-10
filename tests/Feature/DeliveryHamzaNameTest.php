<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryResource\Pages\EditDelivery;
use App\Models\Brand;
use App\Models\InstallmentRequest;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\Staff;
use App\Support\ArabicName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-11-10: pausing a request to ask for the ID photo again was refused with
 * "اسم العميل لا يجب أن يحتوي على (أ، إ، آ)". The bot's form keeps the customer's
 * hamza; the rule then blocked every save on 27 requests until someone retyped the name.
 */
class DeliveryHamzaNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_whose_name_carries_a_hamza_can_still_be_paused(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($staff, 'filament');

        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $machine = Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 70000, 'installment_price' => 75000, 'is_active' => true]);
        InstallmentSystem::create(['name' => 'امان', 'plans' => [['months' => 24, 'interest' => 50]], 'administrative_fees' => 7, 'is_active' => true]);

        $request = InstallmentRequest::create([
            'status' => 'new_request', 'staff_id' => $staff->id, 'installment_type' => 'امان', 'months' => 24,
            'machine_id' => $machine->id, 'machine_installment_price' => 75000, 'deposit' => 15000,
            'applicant_name' => 'أحمد إبراهيم آدم', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29901011234567',
        ]);

        Livewire::test(EditDelivery::class, ['record' => $request->id])
            ->fillForm(['status' => 'paused', 'checks_report' => 'البطاقة محتاجة تتصور تاني'])
            ->call('save')
            ->assertHasNoFormErrors();

        $request->refresh();
        $this->assertSame('paused', $request->status);
        $this->assertSame('احمد ابراهيم ادم', $request->applicant_name);
    }

    public function test_hamza_forms_are_folded_to_alef(): void
    {
        $this->assertSame('احمد ابراهيم ادم', ArabicName::normalize('أحمد إبراهيم آدم'));
        $this->assertNull(ArabicName::normalize(null));
        $this->assertSame('', ArabicName::normalize(''));
    }
}
