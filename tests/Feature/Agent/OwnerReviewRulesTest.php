<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\ReplyGuard;
use App\Domain\Applications\EligibilityRules\AgeRangeEvaluator;
use App\Domain\Settings\AgentInstructions;
use App\Domain\Teaching\ChangeApplier;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Staff;
use App\Models\TeachingChange;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rules from the owner's review of the live chats (2026-09-26). */
class OwnerReviewRulesTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
    }

    private function check(string $reply, array $outcomes = []): ?string
    {
        config(['agent.guard.number_min_value' => 1000]);

        return app(ReplyGuard::class)->check(['messages' => [$reply]], $this->conversation(), '', [], $outcomes);
    }

    private function branchLookup(bool $noBranch = false): array
    {
        return ['name' => 'get_branch_information', 'ok' => true, 'data' => [
            'branches' => [[
                'name' => 'فرع عين شمس', 'city' => 'عين شمس', 'address' => 'ش الزهراء', 'map_url' => 'https://maps.app.goo.gl/hyyo',
                'working_hours' => ['السبت - الخميس' => '10 الصبح - 10 بالليل'],
            ]],
        ] + ($noBranch ? ['no_branch_in_requested_area' => true, 'note' => 'We have NO branch in المنصورة.'] : [])];
    }

    public function test_a_62_year_old_can_take_two_years_but_not_three(): void
    {
        $rule = new AgeRangeEvaluator();
        $params = ['min' => 21, 'max' => 62, 'max_age_at_end' => 64];

        $this->assertSame('eligible', $rule->evaluate($params, ['age' => 62, 'months' => 24])->status);
        $this->assertSame('not_eligible', $rule->evaluate($params, ['age' => 62, 'months' => 36])->status);
        $this->assertSame('not_eligible', $rule->evaluate($params, ['age' => 20])->status);
    }

    public function test_switching_off_a_duration_keeps_the_plan_row_for_old_applications(): void
    {
        $system = InstallmentSystem::create(['name' => 'مايلو', 'plans' => [['months' => 12, 'interest' => 30], ['months' => 18, 'interest' => 40]], 'is_active' => true]);
        $plan18 = InstallmentPlan::where('installment_system_id', $system->id)->where('months', 18)->first();

        $system->update(['plans' => [['months' => 12, 'interest' => 30]], 'is_active' => false]);

        $this->assertFalse($plan18->fresh()->is_active);
        $this->assertTrue(InstallmentPlan::where('installment_system_id', $system->id)->where('months', 12)->value('is_active'));
    }

    public function test_a_branch_named_only_in_the_tools_note_is_invented(): void
    {
        $this->assertSame('BRANCH_NOT_SOURCED', $this->check('إحنا لينا فرع في المنصورة، شارع الجيش.', [$this->branchLookup(true)]));
    }

    public function test_a_map_link_that_is_not_a_branch_link_is_blocked(): void
    {
        $this->assertSame('BRANCH_NOT_SOURCED', $this->check('فرع عين شمس: ش الزهراء https://maps.app.goo.gl/other', [$this->branchLookup()]));
        $this->assertNull($this->check('فرع عين شمس: ش الزهراء https://maps.app.goo.gl/hyyo', [$this->branchLookup()]));
    }

    public function test_saying_an_age_is_fine_needs_a_check(): void
    {
        $this->assertSame('AGE_NOT_CHECKED', $this->check('بص يا هندسة، السن ده تمام جداً للتقسيط.'));
        $this->assertNull($this->check('بص يا هندسة، السن ده تمام جداً للتقسيط.', [
            ['name' => 'check_eligibility', 'ok' => true, 'data' => ['status' => 'eligible']],
        ]));
    }

    public function test_promising_to_tell_him_when_a_model_arrives_is_blocked(): void
    {
        $this->assertSame('AVAILABILITY_PROMISE', $this->check('من عيوني، أول ما توصل هبلغك فوراً.'));
    }

    public function test_teach_mode_finds_a_paragraph_quoted_with_a_small_wording_difference(): void
    {
        $instructions = app(AgentInstructions::class);
        $instructions->publish("# تعليمات\n\n- قوله أسماء جهات التمويل ووضح له إننا بنختار له النظام الأنسب والأوفر ليه تلقائياً.\n- سطر تاني خالص عن الصور.", 'test');

        $change = new TeachingChange([
            'kind' => 'instruction',
            'after' => ['find' => '- قوله أسماء جهات التمويل ووضح له إننا بنختار له النظام الأنسب والأوفر له تلقائياً.', 'replace' => '- قائمة'],
        ]);

        app(ChangeApplier::class)->validate($change);

        $this->assertSame('- قوله أسماء جهات التمويل ووضح له إننا بنختار له النظام الأنسب والأوفر ليه تلقائياً.', $change->after['find']);
    }

    public function test_a_correct_branch_list_saying_all_branches_are_open_passes(): void
    {
        $this->assertNull($this->check("للأسف مفيش فرع لينا في المنصورة.\n\nدي فروعنا المتاحة حالياً:\nفرع عين شمس: ش الزهراء.\n\nكل الفروع شغالة من ١٠ الصبح لـ ١٠ بالليل.", [$this->branchLookup(true)]));
    }
}
