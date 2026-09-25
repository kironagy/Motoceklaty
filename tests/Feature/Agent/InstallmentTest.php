<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\CalculateInstallmentTool;
use App\Agent\Tools\CheckEligibilityTool;
use App\Agent\Tools\GetInstallmentOptionsTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\AiTrace;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentTest extends TestCase
{
    use RefreshDatabase;

    private function machine(array $overrides = []): Machine
    {
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);

        return Machine::create(array_merge([
            'name' => 'بوكسر 150',
            'brand_id' => $brand->id,
            'cash_price' => 50000,
            'installment_price' => 55000,
            'is_active' => true,
            'availability' => 'in_stock',
            'type' => 'normal',
        ], $overrides));
    }

    private function standardSystem(): InstallmentSystem
    {
        return InstallmentSystem::create([
            'name' => 'أمان',
            'pricing_mode' => 'standard',
            'plans' => [['months' => 12, 'interest' => 20], ['months' => 24, 'interest' => 40]],
            'administrative_fees' => 7,
        ]);
    }

    private function zeroFeesSystem(): InstallmentSystem
    {
        return InstallmentSystem::create([
            'name' => 'أمان بدون مصاريف',
            'pricing_mode' => 'zero_fees',
            'plans' => [['months' => 12, 'interest' => 30]],
            'administrative_fees' => 0,
        ]);
    }

    private function ctx(): ToolContext
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_installment_system_observer_syncs_plans_from_json(): void
    {
        $system = $this->standardSystem();

        $this->assertSame(2, InstallmentPlan::where('installment_system_id', $system->id)->count());
        $this->assertEqualsCanonicalizing(
            [12, 24],
            InstallmentPlan::where('installment_system_id', $system->id)->pluck('months')->all()
        );

        $system->update(['plans' => [['months' => 36, 'interest' => 60]]]);

        $this->assertSame(1, InstallmentPlan::where('installment_system_id', $system->id)->count());
        $this->assertSame(36, InstallmentPlan::where('installment_system_id', $system->id)->value('months'));
    }

    public function test_machine_observer_syncs_installment_systems_pivot_from_json(): void
    {
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id]]);

        $this->assertSame([$system->id], $machine->installmentSystems()->pluck('installment_systems.id')->all());

        $machine->update(['installment_systems' => []]);
        $this->assertSame([], $machine->installmentSystems()->pluck('installment_systems.id')->all());
    }

    public function test_standard_pricing_mode_reproduces_the_website_formula(): void
    {
        $machine = $this->machine(['cash_price' => 50000, 'installment_price' => 55000]);
        $system = $this->standardSystem();
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->where('months', 24)->first();

        $result = app(InstallmentCalculator::class)->calculate($machine, $system, $plan, 10000);

        // remaining = 55000 - 10000 = 45000; total = 45000 * 1.40 = 63000
        $this->assertSame(45000.0, $result->financedAmount);
        $this->assertSame(63000.0, $result->totalWithInterest);
        $this->assertSame(3150.0, $result->administrativeFees); // 45000 * 7%
        $this->assertSame(2625.0, $result->monthlyInstallment); // 63000 / 24
    }

    public function test_zero_fees_pricing_mode_uses_cash_price_and_the_75_percent_surcharge(): void
    {
        $machine = $this->machine(['cash_price' => 50000, 'installment_price' => 55000]);
        $system = $this->zeroFeesSystem();
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $result = app(InstallmentCalculator::class)->calculate($machine, $system, $plan, 10000);

        // remaining = 50000 - 10000 = 40000; plus75 = 43000; total = 43000 * 1.30 = 55900
        $this->assertSame(40000.0, $result->financedAmount);
        $this->assertSame(55900.0, $result->totalWithInterest);
        $this->assertSame(0.0, $result->administrativeFees);
        $this->assertSame(4658.0, $result->monthlyInstallment); // round(55900/12)
    }

    public function test_down_payment_at_or_above_cash_price_is_rejected(): void
    {
        $machine = $this->machine(['cash_price' => 50000]);
        $system = $this->standardSystem();
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $this->expectException(InstallmentCalculationException::class);
        app(InstallmentCalculator::class)->calculate($machine, $system, $plan, 50000);
    }

    public function test_down_payment_below_system_minimum_is_rejected(): void
    {
        $machine = $this->machine(['cash_price' => 50000]);
        $system = $this->standardSystem();
        $system->update(['minimum_down_payment' => 5000]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        try {
            app(InstallmentCalculator::class)->calculate($machine, $system, $plan, 1000);
            $this->fail('Expected InstallmentCalculationException');
        } catch (InstallmentCalculationException $e) {
            $this->assertSame('DOWN_PAYMENT_BELOW_MINIMUM', $e->errorCode);
        }
    }

    public function test_get_installment_options_only_lists_systems_linked_to_the_machine(): void
    {
        $linked = $this->standardSystem();
        $this->zeroFeesSystem(); // not linked
        $machine = $this->machine(['installment_systems' => [$linked->id]]);

        $result = app(GetInstallmentOptionsTool::class)->execute(['motorcycle_id' => $machine->id], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['systems']);
        $this->assertSame($linked->id, $result->data['systems'][0]['system_id']);
    }

    public function test_get_installment_options_unknown_motorcycle(): void
    {
        $result = app(GetInstallmentOptionsTool::class)->execute(['motorcycle_id' => 999], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_MOTORCYCLE', $result->error['code']);
    }

    public function test_get_installment_options_no_systems_linked(): void
    {
        $this->standardSystem(); // exists but not linked
        $machine = $this->machine();

        $result = app(GetInstallmentOptionsTool::class)->execute(['motorcycle_id' => $machine->id], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('NO_INSTALLMENT_SYSTEMS', $result->error['code']);
    }

    public function test_calculate_installment_tool_happy_path(): void
    {
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id]]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->where('months', 12)->first();

        $result = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id,
            'plan_id' => $plan->id,
            'down_payment' => 5000,
        ], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertSame(12, $result->data['months']);
        $this->assertSame(50000.0, $result->data['financed_amount']); // 55000 - 5000
        $this->assertSame(60000.0, $result->data['total_payable']); // 50000 * 1.20
        $this->assertSame(5000.0, $result->data['monthly_payment']); // 60000 / 12
    }

    public function test_a_guessed_plan_id_contradicting_the_named_plan_fails_clearly(): void
    {
        $aman = $this->standardSystem();
        $zero = $this->zeroFeesSystem();
        $machine = $this->machine(['installment_systems' => [$aman->id, $zero->id]]);
        $guessed = InstallmentPlan::where('installment_system_id', $zero->id)->first();

        $conflicting = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id, 'plan_id' => $guessed->id,
            'installment_system' => 'امان', 'months' => 24, 'down_payment' => 5000,
        ], $this->ctx());
        $this->assertSame('CONFLICTING_PLAN_ARGUMENTS', $conflicting->error['code']);

        $named = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id, 'installment_system' => 'امان', 'months' => 24, 'down_payment' => 5000,
        ], $this->ctx());
        $this->assertTrue($named->ok);
        $this->assertSame('أمان', $named->data['installment_system']);
        $this->assertSame(24, $named->data['months']);
    }

    public function test_months_without_a_system_is_rejected_not_silently_dropped(): void
    {
        $aman = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$aman->id]]);

        $result = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id, 'months' => 12, 'down_payment' => 30000,
        ], $this->ctx());

        $this->assertSame('SYSTEM_REQUIRED', $result->error['code']);
    }

    public function test_calculate_installment_rejects_a_plan_not_linked_to_the_motorcycle(): void
    {
        $unlinkedSystem = $this->standardSystem();
        $machine = $this->machine(); // no systems linked at all
        $plan = InstallmentPlan::where('installment_system_id', $unlinkedSystem->id)->first();

        $result = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id,
            'plan_id' => $plan->id,
        ], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE', $result->error['code']);
    }

    public function test_check_eligibility_reports_financing_cap_exceeded(): void
    {
        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'Self employed']);
        EligibilityRule::create([
            'customer_type_id' => $type->id,
            'rule_type' => 'financing_cap',
            'params' => ['max_amount' => 30000],
            'is_active' => true,
        ]);

        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'installment_price' => 55000]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->where('months', 12)->first();

        // financed_amount = 55000 - 0 = 55000 > 30000 cap
        $result = app(CheckEligibilityTool::class)->execute([
            'customer_type' => 'self_employed',
            'motorcycle_id' => $machine->id,
            'plan_id' => $plan->id,
        ], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertSame('not_eligible', $result->data['status']);
        $this->assertSame('FINANCING_CAP_EXCEEDED', $result->data['reasons'][0]['code']);
    }

    public function test_check_eligibility_unknown_customer_type(): void
    {
        $result = app(CheckEligibilityTool::class)->execute(['customer_type' => 'ghost'], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_CUSTOMER_TYPE', $result->error['code']);
    }
    private function selfEmployedCappedAt(float $cap): CustomerType
    {
        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        EligibilityRule::create([
            'customer_type_id' => $type->id,
            'rule_type' => 'financing_cap',
            'params' => ['max_amount' => $cap],
            'is_active' => true,
        ]);

        return $type;
    }

    public function test_calculate_installment_moves_the_amount_over_the_cap_to_the_down_payment(): void
    {
        // Owner's example: a 66,000 motorcycle, 60,000 cap -> 6,000 difference + admin fees in cash.
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);

        $result = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id, 'installment_system' => 'امان', 'months' => 12, 'customer_type' => 'self_employed',
        ], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertSame(6000.0, $result->data['down_payment']);
        $this->assertSame(60000.0, $result->data['financed_amount']);
        $this->assertSame(4200.0, $result->data['admin_fee']); // 7% of 60,000
        $this->assertSame(10200.0, $result->data['cash_due_upfront']);
        $this->assertSame([], $result->data['warnings']);
        $this->assertSame(60000.0, $result->data['financing_cap']['max_financed_amount']);
        $this->assertStringContainsString('60,000', $result->data['financing_cap']['explain_to_customer']);
        $this->assertStringContainsString('عامل حر', $result->data['financing_cap']['explain_to_customer']);
    }

    public function test_calculate_installment_keeps_a_down_payment_already_above_the_cap_minimum(): void
    {
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);

        $result = app(CalculateInstallmentTool::class)->execute([
            'motorcycle_id' => $machine->id, 'installment_system' => 'امان', 'months' => 12,
            'down_payment' => 20000, 'customer_type' => 'self_employed',
        ], $this->ctx());

        $this->assertSame(20000.0, $result->data['down_payment']);
        $this->assertArrayNotHasKey('financing_cap', $result->data);
    }

    public function test_installment_options_quote_capped_customers_at_the_down_payment_that_fits(): void
    {
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);

        $result = app(GetInstallmentOptionsTool::class)->execute([
            'motorcycle_id' => $machine->id, 'customer_type' => 'self_employed',
        ], $this->ctx());

        $this->assertTrue($result->ok);
        $aman = $result->data['systems'][0];
        $this->assertSame(6000.0, $aman['minimum_down_payment']);
        $twelve = collect($aman['plans'])->firstWhere('months', 12);
        $this->assertEquals(10200, $twelve['cash_due_upfront']);
        $this->assertEquals(6000, $twelve['monthly_at_minimum_down_payment']); // 60,000 * 1.2 / 12
        $this->assertArrayNotHasKey('warnings', $twelve);
        $this->assertSame(60000.0, $result->data['financing_cap']['max_financed_amount']);
    }

    public function test_installment_options_without_a_customer_type_are_not_capped(): void
    {
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'installment_price' => 66000]);

        $result = app(GetInstallmentOptionsTool::class)->execute(['motorcycle_id' => $machine->id], $this->ctx());

        $this->assertSame(0.0, $result->data['systems'][0]['minimum_down_payment']);
        $this->assertArrayNotHasKey('financing_cap', $result->data);
    }
    private function offerTool(array $args): \App\Agent\Tools\ToolResult
    {
        return app(\App\Agent\Tools\GetInstallmentOfferTool::class)->execute($args, $this->ctx());
    }

    public function test_the_offer_picks_the_cheapest_system_per_duration_without_listing_systems(): void
    {
        $aman = $this->standardSystem(); // 7% fees, 12m 20%, 24m 40%
        $cheapAt12 = InstallmentSystem::create(['name' => 'مايلو', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 22], ['months' => 24, 'interest' => 60]]]);
        $machine = $this->machine(['installment_systems' => [$aman->id, $cheapAt12->id], 'installment_price' => 55000]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id]);

        $this->assertTrue($result->ok);
        $this->assertSame([12, 24], array_column($result->data['offers'], 'months'));
        // 12m: مايلو 55000*1.22 = 67100 vs أمان 55000*1.2 + 3850 fees = 69850
        $this->assertSame('مايلو', $result->data['offers'][0]['installment_system']);
        $this->assertEquals(0, $result->data['offers'][0]['cash_due_upfront']);
        // 24m: أمان 77000 + 3850 = 80850 vs مايلو 88000
        $this->assertSame('أمان', $result->data['offers'][1]['installment_system']);
        $this->assertEquals(3850, $result->data['offers'][1]['cash_due_upfront']);
    }

    public function test_admin_fees_are_quoted_as_fees_at_pickup_not_as_a_down_payment(): void
    {
        $aman = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$aman->id]]);

        $offer = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12])->data['offers'][0];

        // 55000 * 7% = 3850 fees, (55000 * 1.2) / 12 = 5500 a month
        $this->assertEquals(3850, $offer['admin_fee_at_pickup']);
        $this->assertStringContainsString('من غير مقدم', $offer['say']);
        $this->assertStringContainsString('مصاريف إدارية 3,850', $offer['say']);
        $this->assertStringContainsString('5,500', $offer['say']);
        $this->assertStringNotContainsString('مقدم 3,850', $offer['say']);
    }

    public function test_the_first_installment_date_is_part_of_the_offer(): void
    {
        $machine = $this->machine(['installment_systems' => [$this->standardSystem()->id]]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id]);

        $this->assertSame(45, $result->data['first_payment_after_days']);
        $this->assertStringContainsString('45 يوم', $result->data['how_to_present']);
    }

    public function test_the_no_upfront_system_is_offered_only_when_the_customer_insists(): void
    {
        $aman = $this->standardSystem();
        $noUpfront = InstallmentSystem::create(['name' => 'امان بدون مصاريف', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'no_upfront_only' => true, 'plans' => [['months' => 12, 'interest' => 30], ['months' => 24, 'interest' => 60]]]);
        $machine = $this->machine(['installment_systems' => [$aman->id, $noUpfront->id]]);

        $normal = $this->offerTool(['motorcycle_id' => $machine->id]);
        $this->assertSame(['أمان', 'أمان'], array_column($normal->data['offers'], 'installment_system'));

        $insists = $this->offerTool(['motorcycle_id' => $machine->id, 'no_upfront' => true]);
        $this->assertSame(['امان بدون مصاريف', 'امان بدون مصاريف'], array_column($insists->data['offers'], 'installment_system'));
        $this->assertEquals(0, $insists->data['offers'][0]['cash_due_upfront']);
        // 55000 * 1.3 / 12
        $this->assertEquals(5958, $insists->data['offers'][0]['monthly_payment']);
        $this->assertStringContainsString('من غير مقدم ومن غير أي مصاريف', $insists->data['offers'][0]['say']);
    }

    public function test_no_upfront_with_no_such_system_says_so(): void
    {
        $machine = $this->machine(['installment_systems' => [$this->standardSystem()->id]]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id, 'no_upfront' => true]);

        $this->assertSame('NO_ZERO_UPFRONT_PLAN', $result->error['code']);
    }

    public function test_the_offer_sticks_to_the_duration_already_chosen_on_the_application(): void
    {
        $aman = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$aman->id]]);
        $ctx = $this->ctx();
        $conversation = WhatsappConversation::find($ctx->conversationId);
        $customer = \App\Models\Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '2099@s.whatsapp.net', 'phone' => '2099']);
        $application = \App\Models\Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id, 'status' => 'collecting',
            'customer_type_id' => CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر'])->id,
            'machine_id' => $machine->id, 'installment_plan_id' => $aman->installmentPlans()->where('months', 24)->value('id')]);

        $result = app(\App\Agent\Tools\GetInstallmentOfferTool::class)->execute(['motorcycle_id' => $machine->id], $ctx->withActiveApplication($application->id));

        $this->assertSame([24], array_column($result->data['offers'], 'months'));
    }

    public function test_the_offer_skips_systems_whose_conditions_the_customer_does_not_meet(): void
    {
        $type = CustomerType::create(['key' => 'pension', 'label' => 'معاش']);
        $aman = $this->standardSystem();
        $giza = InstallmentSystem::create(['name' => 'رخيص الجيزة', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 1]], 'governorates' => ['giza']]);
        $employeesOnly = InstallmentSystem::create(['name' => 'رخيص للموظفين', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 2]], 'customer_type_ids' => [999]]);
        $off = InstallmentSystem::create(['name' => 'متوقف', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 0]], 'is_active' => false]);
        $machine = $this->machine(['installment_systems' => [$aman->id, $giza->id, $employeesOnly->id, $off->id]]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12, 'customer_type' => 'pension']);
        $this->assertSame('أمان', $result->data['offers'][0]['installment_system']);

        $inGiza = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12, 'customer_type' => 'pension', 'governorate' => 'giza']);
        $this->assertSame('رخيص الجيزة', $inGiza->data['offers'][0]['installment_system']);
    }

    public function test_the_offer_explains_a_financing_cap_and_reports_missing_durations(): void
    {
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12, 'customer_type' => 'self_employed']);
        $this->assertEquals(10200, $result->data['offers'][0]['cash_due_upfront']);
        $this->assertStringContainsString('60,000', $result->data['explain_to_customer']);

        $missing = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 18]);
        $this->assertSame('DURATION_NOT_AVAILABLE', $missing->error['code']);
    }
}
