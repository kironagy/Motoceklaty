<?php

namespace Tests\Feature\Agent;

use App\Domain\Applications\RequirementService;
use App\Domain\Documents\DocumentRules\MatchesApplicationFieldEvaluator;
use App\Domain\Installments\FinancingCapPolicy;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationRequirement;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\EligibilityRule;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use Database\Seeders\BusinessOwnerRequirementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-09-26: a business owner is not a freelancer, and an address is never "any address". */
class BusinessOwnerRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): CustomerType
    {
        $selfEmployed = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        EligibilityRule::create(['customer_type_id' => $selfEmployed->id, 'rule_type' => 'financing_cap', 'params' => ['max_amount' => 60000], 'is_active' => true]);

        foreach (['full_name' => 'person_name', 'phone' => 'phone', 'national_id' => 'national_id', 'address' => 'address', 'work_address' => 'address'] as $key => $type) {
            $field = RequirementField::create(['key' => $key, 'label' => $key, 'data_type' => $type, 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
            ApplicationRequirement::create(['customer_type_id' => $selfEmployed->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true,
                'condition' => $key === 'work_address' ? ['op' => 'in', 'fact' => 'work_type', 'value' => ['craftsman']] : null]);
        }

        DocumentType::create(['key' => 'national_id_front', 'label' => 'بطاقة', 'description_for_ai' => 'id', 'accepted_mimes' => ['image/jpeg'],
            'extraction_fields' => ['full_name', 'national_id'], 'validation_rules' => [], 'is_active' => true]);

        $this->seed(BusinessOwnerRequirementsSeeder::class);

        return $selfEmployed;
    }

    public function test_a_business_owner_has_no_financing_cap_and_owes_a_photo_of_the_place(): void
    {
        $this->seedBase();
        $owner = CustomerType::where('key', 'business_owner')->firstOrFail();

        $this->assertNull(app(FinancingCapPolicy::class)->capFor($owner->id));

        $requirements = app(RequirementService::class)->requirementsFor($owner, []);
        $documents = collect($requirements['documents'])->pluck('required', 'key')->all();
        $fields = array_column($requirements['fields'], 'key');

        $this->assertTrue($documents['business_place_photo']);
        $this->assertFalse($documents['tax_card']);
        $this->assertContains('address_floor', $fields);
        $this->assertContains('residence_ownership', $fields);
        $this->assertContains('work_landmark', $fields);
    }

    public function test_every_home_address_now_needs_all_its_parts_and_the_work_address_keeps_its_condition(): void
    {
        $selfEmployed = $this->seedBase();

        $fields = array_column(app(RequirementService::class)->requirementsFor($selfEmployed, [])['fields'], 'key');
        $this->assertContains('address_building_no', $fields);
        $this->assertContains('address_landmark', $fields);
        $this->assertNotContains('work_building_no', $fields);

        $craftsman = array_column(app(RequirementService::class)->requirementsFor($selfEmployed, ['work_type' => 'craftsman'])['fields'], 'key');
        $this->assertContains('work_building_no', $craftsman);
        // no floor and no rented/owned for the work address
        $this->assertSame(1, count(array_filter($craftsman, fn ($k) => str_contains($k, 'floor'))));
    }

    public function test_the_seeder_can_run_twice(): void
    {
        $this->seedBase();
        $count = ApplicationRequirement::count();

        $this->seed(BusinessOwnerRequirementsSeeder::class);

        $this->assertSame($count, ApplicationRequirement::count());
    }

    public function test_the_place_name_must_be_the_name_on_the_tax_card(): void
    {
        $this->seedBase();
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation = \App\Models\WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'customer_id' => $customer->id, 'phone' => '2011', 'status' => 'open']);
        $application = Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => CustomerType::where('key', 'business_owner')->value('id'), 'status' => 'collecting']);
        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'business_name', 'value' => 'مطعم الأمل للمأكولات', 'source' => 'document', 'status' => 'valid']);

        $params = DocumentType::where('key', 'business_place_photo')->value('validation_rules')[0]['params'];
        $evaluator = app(MatchesApplicationFieldEvaluator::class);

        $this->assertNull($evaluator->evaluate($params, ['business_name' => 'مطعم الامل'], $application));
        $this->assertSame('BUSINESS_NAME_MISMATCH', $evaluator->evaluate($params, ['business_name' => 'كافيه النجوم'], $application)['code']);
    }
}
