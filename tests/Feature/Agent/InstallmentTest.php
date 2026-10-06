<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
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

        // the companies are listed only when he asks who finances (owner's rule)
        \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'بتقسطوا مع مين؟']);

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

        // Dropped durations are deactivated, never deleted: applications reference plans.
        $this->assertSame(1, InstallmentPlan::where('installment_system_id', $system->id)->where('is_active', true)->count());
        $this->assertSame(36, InstallmentPlan::where('installment_system_id', $system->id)->where('is_active', true)->value('months'));
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

    public function test_installment_options_quote_capped_customers_at_the_down_payment_that_fits(): void
    {
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);

        $ctx = $this->ctx();
        $this->worksAs($ctx, 'self_employed');
        $result = app(GetInstallmentOptionsTool::class)->execute([
            'motorcycle_id' => $machine->id, 'customer_type' => 'self_employed',
        ], $ctx);

        $this->assertTrue($result->ok, json_encode($result->error ?? null));
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
        $ctx = $this->ctx();

        if (isset($args['customer_type'])) {
            $this->worksAs($ctx, $args['customer_type']);
        }

        return app(\App\Agent\Tools\GetInstallmentOfferTool::class)->execute($args, $ctx);
    }

    /** Rebuild: a type is priced only when his recorded work (record_work_profile) supports it. */
    private function worksAs(ToolContext $ctx, string $type): void
    {
        $words = ['self_employed' => 'شغال حر', 'pension' => 'انا على المعاش', 'employee' => 'انا موظف متأمن عليا'][$type] ?? 'شغال';
        \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $ctx->conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $words]);
        app(\App\Agent\Tools\RecordWorkProfileTool::class)->execute(['evidence' => $words, 'occupation' => $words, 'work_stated' => true, 'customer_type' => $type,
            'working_now' => 'yes', 'relation_to_workplace' => $type === 'employee' ? 'works_for_someone' : 'independent',
            'insured' => $type === 'employee' ? 'yes' : 'unknown'] + ($type === 'self_employed' ? ['work_type' => 'other'] : []), $ctx);
    }

    public function test_the_offer_picks_the_cheapest_system_per_duration_without_listing_systems(): void
    {
        $aman = $this->standardSystem(); // 7% fees, 12m 20%, 24m 40%
        $cheapAt12 = InstallmentSystem::create(['name' => 'مايلو', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 22], ['months' => 24, 'interest' => 60]]]);
        $machine = $this->machine(['installment_systems' => [$aman->id, $cheapAt12->id], 'installment_price' => 55000]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id, 'all_durations' => true]);

        $this->assertTrue($result->ok);
        $this->assertSame([12, 24], array_column($result->data['offers'], 'months'));
        // 12m: مايلو 55000*1.22 = 67100 vs أمان 55000*1.2 + 3850 fees = 69850
        $this->assertSame('مايلو', $result->data['offers'][0]['installment_system']);
        $this->assertEquals(0, $result->data['offers'][0]['cash_due_upfront']);
        // 24m: أمان 77000 + 3850 = 80850 vs مايلو 88000
        $this->assertSame('أمان', $result->data['offers'][1]['installment_system']);
        $this->assertEquals(3850, $result->data['offers'][1]['cash_due_upfront']);
    }

    private function fourDurationMachine(): Machine
    {
        $system = InstallmentSystem::create(['name' => 'أمان', 'pricing_mode' => 'standard', 'administrative_fees' => 7,
            'plans' => [['months' => 12, 'interest' => 20], ['months' => 18, 'interest' => 30], ['months' => 24, 'interest' => 40], ['months' => 36, 'interest' => 60]]]);

        return $this->machine(['installment_systems' => [$system->id], 'installment_price' => 55000]);
    }

    /**
     * Owner 2026-09-29 (conversation 708): "القسط كام؟" got all four
     * durations. With no duration named he is asked which one first - the
     * durations by name, no numbers.
     */
    public function test_without_a_duration_he_is_asked_which_one_before_any_number(): void
    {
        $result = $this->offerTool(['motorcycle_id' => $this->fourDurationMachine()->id]);

        $this->assertTrue($result->ok);
        $this->assertArrayNotHasKey('offers', $result->data);
        $this->assertSame(['سنة', 'سنة ونص', 'سنتين', '٣ سنين'], $result->data['durations']);
        // how to ask is the instructions' job (§5); the tool returns facts only
        $this->assertArrayNotHasKey('how_to_present', $result->data);
    }

    /** Several durations with their numbers only when he asks for more than one. */
    public function test_several_durations_are_quoted_only_when_he_asks_for_them(): void
    {
        $machine = $this->fourDurationMachine();

        $two = $this->offerTool(['motorcycle_id' => $machine->id, 'months_list' => [12, 24]]);
        $this->assertSame([12, 24], array_column($two->data['offers'], 'months'));

        $all = $this->offerTool(['motorcycle_id' => $machine->id, 'all_durations' => true]);
        $this->assertSame([12, 18, 24, 36], array_column($all->data['offers'], 'months'));
        $this->assertStringContainsString('سنتين', $all->data['offers'][2]['line']);
    }

    public function test_the_admin_fee_is_never_added_to_the_total(): void
    {
        // The owner: "متضفش المصاريف الاداريه علي اجمالي قسط المكنه".
        $machine = $this->machine(['installment_systems' => [$this->standardSystem()->id]]);

        $offer = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12])->data['offers'][0];

        // 55000 * 1.2 = 66000 in 12 × 5,500; the 3,850 fee stays apart
        $this->assertEquals(66000, $offer['total_paid']);
        $this->assertStringContainsString('12 قسط × 5,500 جنيه = 66,000 جنيه في الآخر.', $offer['breakdown']);
        $this->assertStringContainsString('والمصاريف الإدارية 3,850 جنيه لوحدها', $offer['breakdown']);
        $this->assertStringNotContainsString('69,850', $offer['breakdown']);
    }

    public function test_admin_fees_are_quoted_as_fees_at_pickup_not_as_a_down_payment(): void
    {
        $aman = $this->standardSystem();
        $machine = $this->machine(['installment_systems' => [$aman->id]]);

        $offer = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12])->data['offers'][0];

        // 55000 * 7% = 3850 fees, (55000 * 1.2) / 12 = 5500 a month
        $this->assertEquals(3850, $offer['admin_fee_at_pickup']);
        $this->assertStringNotContainsString('مقدم', $offer['line']);
        $this->assertStringContainsString('مصاريف إدارية 3,850', $offer['line']);
        $this->assertStringContainsString('5,500', $offer['line']);
        $this->assertStringNotContainsString('مقدم 3,850', $offer['line']);
    }

    public function test_the_first_installment_date_is_part_of_the_offer(): void
    {
        $machine = $this->machine(['installment_systems' => [$this->standardSystem()->id]]);

        $result = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 12]);

        // the owner: "بعد ٤٥ يوم من الاستلام", never "لحد ٤٥ يوم"
        $this->assertSame('أول قسط بيبدأ بعد 45 يوم من الاستلام', $result->data['first_payment']);
        $this->assertArrayNotHasKey('installment_price', $result->data);
        $this->assertStringNotContainsString('بالتقسيط', $result->data['offers'][0]['breakdown']);
    }

    public function test_the_no_upfront_system_is_offered_only_when_the_customer_insists(): void
    {
        $aman = $this->standardSystem();
        $noUpfront = InstallmentSystem::create(['name' => 'امان بدون مصاريف', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'no_upfront_only' => true, 'plans' => [['months' => 12, 'interest' => 30], ['months' => 24, 'interest' => 60]]]);
        $machine = $this->machine(['installment_systems' => [$aman->id, $noUpfront->id]]);

        $normal = $this->offerTool(['motorcycle_id' => $machine->id, 'all_durations' => true]);
        $this->assertSame(['أمان', 'أمان'], array_column($normal->data['offers'], 'installment_system'));

        $insists = $this->offerTool(['motorcycle_id' => $machine->id, 'no_upfront' => true, 'all_durations' => true]);
        $this->assertSame(['امان بدون مصاريف', 'امان بدون مصاريف'], array_column($insists->data['offers'], 'installment_system'));
        $this->assertEquals(0, $insists->data['offers'][0]['cash_due_upfront']);
        // 55000 * 1.3 / 12
        $this->assertEquals(5958, $insists->data['offers'][0]['monthly_payment']);
        $this->assertStringContainsString('مفيش أي مبلغ بيتدفع وقت الاستلام', $insists->data['offers'][0]['line']);
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
        // the owner: the cap and its number are never told - only that part is paid up front
        $this->assertStringContainsString('بيتدفع كاش وقت الاستلام', $result->data['cap_reason']);
        $this->assertStringNotContainsString('60,000', $result->data['cap_reason']);

        $missing = $this->offerTool(['motorcycle_id' => $machine->id, 'months' => 18, 'customer_type' => 'self_employed']);
        $this->assertSame('DURATION_NOT_AVAILABLE', $missing->error['code']);
    }

    public function test_above_the_freelancer_cap_the_work_comes_before_any_installment(): void
    {
        // The owner: under 60,000 quote first and ask the work at the end;
        // above it (an F250) ask the work first - he may owe part up front.
        $this->selfEmployedCappedAt(60000);
        $system = $this->standardSystem();
        $big = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 62000, 'installment_price' => 66000]);
        $small = $this->machine(['installment_systems' => [$system->id], 'cash_price' => 41000, 'installment_price' => 47000]);

        $first = $this->offerTool(['motorcycle_id' => $big->id]);
        $this->assertSame('ASK_WORK_FIRST', $first->error['code']);
        $this->assertStringContainsString('62,000', $first->error['detail']);

        $this->assertTrue($this->offerTool(['motorcycle_id' => $big->id, 'customer_type' => 'self_employed'])->ok);
        $this->assertTrue($this->offerTool(['motorcycle_id' => $small->id])->ok);
    }
}
