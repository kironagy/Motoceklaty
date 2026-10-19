<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-10-19: Amaan Giza and Amaan Cairo are two companies - a request never moves
 * between them, either way and whoever asks (the owner too).
 */
class AmaanGizaCairoTest extends TestCase
{
    use RefreshDatabase;

    private function request(string $type): InstallmentRequest
    {
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $machine = Machine::create(['name' => 'L250', 'brand_id' => $brand->id, 'cash_price' => 55000, 'installment_price' => 59000, 'is_active' => true]);

        return InstallmentRequest::create([
            'status' => 'new', 'installment_type' => $type, 'months' => 12,
            'machine_id' => $machine->id, 'machine_installment_price' => 59000, 'deposit' => 0,
            'applicant_name' => 'حسن محمد', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29901011234567',
        ]);
    }

    private function actAs(bool $owner): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_admin' => false, 'is_super_admin' => false]);
        $staff->forceFill(['is_hitler' => $owner])->save();
        $this->actingAs($staff, 'filament');
    }

    public function test_giza_never_moves_to_cairo_even_by_the_owner(): void
    {
        $this->actAs(owner: true);
        $request = $this->request(InstallmentRequest::AMAAN_GIZA);

        $request->update(['installment_type' => InstallmentRequest::AMAAN_CAIRO]);

        $this->assertSame(InstallmentRequest::AMAAN_GIZA, $request->fresh()->installment_type);
    }

    public function test_cairo_never_moves_to_giza(): void
    {
        $this->actAs(owner: false);
        $request = $this->request(InstallmentRequest::AMAAN_CAIRO);

        $request->update(['installment_type' => InstallmentRequest::AMAAN_GIZA]);

        $this->assertSame(InstallmentRequest::AMAAN_CAIRO, $request->fresh()->installment_type);
    }

    public function test_other_companies_still_change_freely(): void
    {
        $this->actAs(owner: false);
        $request = $this->request(InstallmentRequest::AMAAN_GIZA);

        $request->update(['installment_type' => 'امان']);
        $this->assertSame('امان', $request->fresh()->installment_type);

        $request->update(['installment_type' => InstallmentRequest::AMAAN_CAIRO]);
        $this->assertSame(InstallmentRequest::AMAAN_CAIRO, $request->fresh()->installment_type);
    }

    public function test_the_rule_itself(): void
    {
        $this->assertTrue(InstallmentRequest::isAmaanCrossMove(InstallmentRequest::AMAAN_GIZA, InstallmentRequest::AMAAN_CAIRO));
        $this->assertTrue(InstallmentRequest::isAmaanCrossMove(InstallmentRequest::AMAAN_CAIRO, InstallmentRequest::AMAAN_GIZA));
        $this->assertFalse(InstallmentRequest::isAmaanCrossMove(InstallmentRequest::AMAAN_GIZA, InstallmentRequest::AMAAN_GIZA));
        $this->assertFalse(InstallmentRequest::isAmaanCrossMove(null, InstallmentRequest::AMAAN_CAIRO));
        $this->assertFalse(InstallmentRequest::isAmaanCrossMove('امان', InstallmentRequest::AMAAN_GIZA));
    }
}
