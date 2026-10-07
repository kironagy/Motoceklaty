<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetInstallmentOfferTool;
use App\Agent\Tools\RecordCustomerDataTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Settings\AgentInstructions;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner's design: no regex on his text decides behaviour (the agent
 * decides, PHP checks evidence), and tools return facts and codes - what
 * to do with each code is written once, in the instructions.
 */
class FactsNotProseTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function says($conversation, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
    }

    private function ctx($conversation, ?int $applicationId = null): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext($conversation->customer_id, $conversation->id, $applicationId, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_a_tool_on_another_model_than_the_words_he_wrote_is_not_blocked_by_php(): void
    {
        // the old gate read "دايو 4" in his message and refused a call on هوجن 4
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        Brand::create(['name' => 'دايو', 'image' => 'd.jpg']);
        $machine = Machine::create(['name' => 'هوجن 4', 'brand_id' => $brand->id, 'cash_price' => 60000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'بكام دايو 4؟');

        $result = app(\App\Agent\Tools\ToolRegistry::class)->execute('get_motorcycle_details', ['motorcycle_ids' => [$machine->id]], $this->ctx($conversation));

        $this->assertNotSame('NOT_THE_MODEL_HE_NAMED', $result['error']['code'] ?? null);
        $this->assertTrue($result['ok'], json_encode($result));
    }

    public function test_none_for_an_address_part_needs_the_agents_flag_and_his_own_words(): void
    {
        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        RequirementField::create(['key' => 'address_floor', 'label' => 'الدور', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->says($conversation, 'معرفش الدور كام بصراحة');
        $tool = app(RecordCustomerDataTool::class);

        // no quote: not saved, whatever his messages say
        $this->assertFalse($tool->execute(['fields' => [['key' => 'address_floor', 'value' => 'مش عارف', 'none' => true]]], $this->ctx($conversation, $application->id))->ok);

        $saved = $tool->execute(['fields' => [['key' => 'address_floor', 'value' => 'مش عارف', 'none' => true, 'quote' => 'معرفش الدور كام']]], $this->ctx($conversation, $application->id));
        $this->assertTrue($saved->ok);
        $this->assertSame('لا يوجد', ApplicationData::where('field_key', 'address_floor')->value('value'));
    }

    public function test_every_code_the_tools_emit_has_its_rule_in_the_instructions(): void
    {
        $instructions = app(AgentInstructions::class)->fromFile()['text'];
        $sources = file_get_contents(app_path('Domain/Applications/WorkClassification.php'))
            .file_get_contents(app_path('Domain/Applications/SnapshotService.php'));

        preg_match_all("/'code' => '([A-Z_]+)'/", file_get_contents(app_path('Domain/Applications/WorkClassification.php')), $codes);
        preg_match_all("/'(?:rule|reason)' => '([a-z_]+)'/", $sources, $rules);
        $expected = array_unique(array_merge($codes[1], $rules[1], [
            'decided_by', 'AGE_CHANGED_AFTER_REFUSAL', 'age_checked', 'papers', 'cap_reason', 'difference_in_down_payment', 'line',
            'why_installment_costs_more', 'rejection_reason', 'earnings_months', 'id_shows_job', 'differs_from_file', 'different_person',
            'identity_replaced', 'not_documents', 'address_missing_place', 'ambiguous_names', 'not_carried', 'similar_available', 'closest_to',
            'deal.interest', 'vehicle_count', 'source_kind', 'no_branch_in_requested_area', 'nearest_branch', 'CUSTOMER_CONFIRMATION_REQUIRED',
            'resubmitted', 'reopened', 'credit_record_final', 'call_requested', 'both_sides', 'ask_together', 'skipped_after_two_asks',
        ]));

        $missing = array_values(array_filter($expected, fn ($code) => ! str_contains($instructions, '`'.$code) && ! str_contains($instructions, $code.'`')));

        $this->assertSame([], $missing, 'codes with no rule in agent.md');
    }

    public function test_an_offer_carries_facts_and_the_owners_arabic_lines_only(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $system = \App\Models\InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->update(['installment_systems' => [$system->id]]);
        CustomerType::create(['key' => 'employee', 'label' => 'موظف']);

        $result = app(GetInstallmentOfferTool::class)->execute(['motorcycle_id' => $machine->id, 'months' => 12, 'customer_type' => 'employee'], $this->ctx($conversation));

        $json = json_encode($result->data ?? $result->error, JSON_UNESCAPED_UNICODE);
        foreach (['"say"', '"note"', '"tell_customer"', '"price_difference_policy"', '"explain_to_customer"'] as $key) {
            $this->assertStringNotContainsString($key, $json);
        }
    }
}
