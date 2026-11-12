<?php

namespace Tests\Feature;

use App\Domain\Conversations\BotSessions;
use App\Domain\Requests\CustomerEditLog;
use App\Filament\Resources\DeliveryResource;
use App\Filament\Widgets\RequestsByTypeOverview;
use App\Models\{Brand, InstallmentRequest, InstallmentSystem, Machine, Staff, WhatsappBot, WhatsappConversation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Owner 2026-11-13: decision from the owner's number, customer edits logged, Aman super admin sees all. */
class OwnerRequests20261113Test extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = []): Staff
    {
        $staff = Staff::create(['name' => 'S'.uniqid(), 'email' => uniqid().'@x.test', 'password' => 'secret']);
        $staff->forceFill($flags)->save();

        return $staff->fresh();
    }

    private function request(array $attributes = []): InstallmentRequest
    {
        $machine = Machine::create(['name' => 'M'.uniqid(), 'brand_id' => Brand::create(['name' => 'B'.uniqid(), 'image' => 'x.png'])->id,
            'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true]);

        $request = InstallmentRequest::create([
            'status' => 'new_request', 'installment_type' => 'امان', 'months' => 12, 'machine_id' => $machine->id,
            'machine_installment_price' => 55000, 'deposit' => 0, 'applicant_name' => 'عميل',
            'applicant_phone' => '010'.random_int(10000000, 99999999), 'applicant_national_id' => '2990101'.random_int(1000000, 9999999),
        ]);
        $request->forceFill($attributes)->save();

        return $request->fresh();
    }

    public function test_decision_goes_out_from_the_number_of_the_staff_who_owns_the_request(): void
    {
        $ahmed = $this->staff();
        $sama = $this->staff();
        $samaBot = WhatsappBot::create(['name' => 'sama', 'staff_id' => $sama->id, 'is_active' => true]);
        $ahmedBot = WhatsappBot::create(['name' => 'ahmed', 'staff_id' => $ahmed->id, 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $samaBot->id, 'phone' => '201000000001', 'status' => 'open']);

        Http::fake(['*/status' => Http::response(['sessions' => [
            ['bot_id' => $samaBot->id, 'connected' => true], ['bot_id' => $ahmedBot->id, 'connected' => true],
        ]])]);

        $live = app(BotSessions::class)->liveConversation($conversation, $ahmed->id);
        $this->assertSame($ahmedBot->id, (int) $live->whatsapp_bot_id);

        // no staff, or a staff without a number: the chat's own connected number as before
        $other = WhatsappConversation::create(['whatsapp_bot_id' => $samaBot->id, 'phone' => '201000000002', 'status' => 'open']);
        $this->assertSame($samaBot->id, (int) app(BotSessions::class)->liveConversation($other, $this->staff()->id)->whatsapp_bot_id);
        $this->assertSame($samaBot->id, (int) app(BotSessions::class)->liveConversation($other)->whatsapp_bot_id);
    }

    public function test_customer_edit_is_logged_field_by_field(): void
    {
        $request = $this->request(['status' => 'paused', 'applicant_street' => 'شارع قديم', 'applicant_birthdate' => '1990-01-01', 'deposit' => 1000]);
        $before = $request->getAttributes();

        $request->forceFill([
            'applicant_street' => 'شارع جديد', 'applicant_id_image' => 'requests/new.jpg',
            'applicant_birthdate' => '1990-01-01', 'deposit' => '1000.00', 'status' => 'new_request',
        ])->saveQuietly();

        $changed = CustomerEditLog::record($request, $before, 'customer_form');

        $this->assertSame(['صورة البطاقة (وش)', 'الشارع'], $changed);
        $activity = Activity::where('subject_id', $request->id)->latest('id')->first();
        $this->assertSame('شارع قديم', $activity->properties['old']['applicant_street']);
        $this->assertSame('new_request', $activity->properties['attributes']['status']);
        $this->assertStringContainsString('الشارع', CustomerEditLog::noteLine($changed, 'رابط الفورم'));
    }

    public function test_aman_employee_who_is_super_admin_sees_everything(): void
    {
        $system = InstallmentSystem::create(['name' => 'امان']);
        $outside = $this->request(['request_type' => 'bot', 'installment_type' => 'شركة تانية']);
        $super = $this->staff(['is_company_employee' => true, 'is_admin' => true, 'is_super_admin' => true]);
        $super->installmentSystems()->attach($system->id);
        $this->actingAs($super, 'filament');

        $this->assertContains($outside->id, DeliveryResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(RequestsByTypeOverview::canView());

        // admin + Aman without super admin stays limited to his companies
        $admin = $this->staff(['is_company_employee' => true, 'is_admin' => true]);
        $admin->installmentSystems()->attach($system->id);
        $this->actingAs($admin, 'filament');
        $this->assertNotContains($outside->id, DeliveryResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(RequestsByTypeOverview::canView());
    }
}
