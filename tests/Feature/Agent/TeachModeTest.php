<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Domain\Settings\AgentInstructions;
use App\Domain\Teaching\ChangeApplier;
use App\Domain\Teaching\LessonBook;
use App\Domain\Teaching\RegressionRunner;
use App\Domain\Teaching\TeachingCoach;
use App\Jobs\RunTeachingRegression;
use App\Models\BotLesson;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Staff;
use App\Models\TeachingCase;
use App\Models\TeachingChange;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeachModeTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): InstallmentPlan
    {
        $system = InstallmentSystem::create(['name' => 'نظام', 'pricing_mode' => 'standard', 'plans' => [], 'is_active' => true]);

        return InstallmentPlan::create(['installment_system_id' => $system->id, 'months' => 12, 'interest_percent' => 30, 'is_active' => true]);
    }

    private function change(array $attributes): TeachingChange
    {
        return TeachingChange::create($attributes + ['status' => 'proposed']);
    }

    public function test_data_change_applies_and_reverts(): void
    {
        $plan = $this->plan();
        $change = $this->change(['kind' => 'data_update', 'target_type' => 'installment_plan', 'target_id' => (string) $plan->id, 'after' => ['interest_percent' => '35']]);

        app(ChangeApplier::class)->apply($change);
        $this->assertEquals(35, $plan->fresh()->interest_percent);
        $this->assertEquals(['interest_percent' => 30], $change->fresh()->before);

        app(ChangeApplier::class)->revert($change->fresh());
        $this->assertEquals(30, $plan->fresh()->interest_percent);
        $this->assertSame('reverted', $change->fresh()->status);
    }

    public function test_fields_outside_the_whitelist_are_rejected(): void
    {
        $plan = $this->plan();
        $change = $this->change(['kind' => 'data_update', 'target_type' => 'installment_plan', 'target_id' => (string) $plan->id, 'after' => ['id' => 99]]);

        $this->expectException(\InvalidArgumentException::class);
        app(ChangeApplier::class)->apply($change);
    }

    public function test_revert_refuses_to_overwrite_a_later_manual_edit(): void
    {
        $plan = $this->plan();
        $change = $this->change(['kind' => 'data_update', 'target_type' => 'installment_plan', 'target_id' => (string) $plan->id, 'after' => ['interest_percent' => 35]]);
        app(ChangeApplier::class)->apply($change);
        $plan->update(['interest_percent' => 40]);

        $this->expectException(\RuntimeException::class);
        app(ChangeApplier::class)->revert($change->fresh());
    }

    public function test_instruction_edit_needs_an_exact_single_match(): void
    {
        $text = app(AgentInstructions::class)->current()['text'];
        $line = trim(explode("\n", $text)[0]);

        $bad = $this->change(['kind' => 'instruction', 'target_type' => 'instructions', 'after' => ['find' => 'نص مش موجود خالص', 'replace' => 'x']]);
        $this->assertThrows(fn () => app(ChangeApplier::class)->validate($bad), \InvalidArgumentException::class);

        $good = $this->change(['kind' => 'instruction', 'target_type' => 'instructions', 'summary' => 't', 'after' => ['find' => $line, 'replace' => $line.' (معدّل)']]);
        app(ChangeApplier::class)->apply($good);
        $this->assertStringContainsString('(معدّل)', app(AgentInstructions::class)->current()['text']);

        app(ChangeApplier::class)->revert($good->fresh());
        $this->assertStringNotContainsString('(معدّل)', app(AgentInstructions::class)->current()['text']);
    }

    public function test_lessons_reach_the_prompt_within_scope(): void
    {
        BotLesson::create(['title' => 'السلام', 'rule' => 'رد السلام وعرّف نفسك', 'fixed_facts' => ['الحاوي'], 'example_reply' => 'وعليكم السلام، معاك الحاوي']);
        BotLesson::create(['title' => 'للموظفين', 'rule' => 'اسأل عن جهة العمل', 'scope_customer_types' => ['employee']]);
        BotLesson::create(['title' => 'مقفول', 'rule' => 'x', 'is_active' => false]);

        $text = app(LessonBook::class)->forPrompt('freelancer')['text'];

        $this->assertStringContainsString('رد السلام وعرّف نفسك', $text);
        $this->assertStringContainsString('الحاوي', $text);
        $this->assertStringNotContainsString('جهة العمل', $text);
        $this->assertStringNotContainsString('مقفول', $text);
        $this->assertStringContainsString('جهة العمل', app(LessonBook::class)->forPrompt('employee')['text']);
    }

    public function test_coach_applies_lesson_and_waits_for_approval_on_data(): void
    {
        Queue::fake();
        $plan = $this->plan();

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => false]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => 'sim-1', 'status' => 'open']);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $bot->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'السلام عليكم']);
        $reply = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $bot->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => 'مساء الفل حابب تسأل على انهي موتوسيكل']);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse([json_encode([
            'understanding' => 'فهمت إن السلام يترد ويعرّف نفسه',
            'question' => '',
            'expectation' => 'يرد السلام ويعرّف نفسه الحاوي',
            'must_contain' => ['الحاوي'],
            'stage' => 'first_message',
            'operations' => [
                ['kind' => 'lesson', 'summary' => 'طريقة السلام', 'title' => 'السلام', 'rule' => 'رد السلام وعرّف نفسك', 'fixed_facts' => ['الحاوي'], 'scope_stage' => 'first_message'],
                ['kind' => 'data_update', 'summary' => 'فايدة 12 شهر', 'entity' => 'installment_plan', 'record_id' => (string) $plan->id, 'changes' => [['field' => 'interest_percent', 'value' => '33']]],
            ],
        ], JSON_UNESCAPED_UNICODE)], [], 'STOP', [], 'fake', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $runner = \Mockery::mock(RegressionRunner::class);
        $runner->shouldReceive('related')->andReturn(collect());
        $runner->shouldReceive('run')->andReturn(['pass' => true, 'reply' => 'وعليكم السلام معاك الحاوي', 'reason' => '']);
        $this->app->instance(RegressionRunner::class, $runner);

        $session = app(TeachingCoach::class)->teach($conversation, $reply->id, 'غلط، الصح وعليكم السلام معاك الحاوي', $staff->id);

        $this->assertSame('awaiting_approval', $session->status);
        $this->assertSame(1, BotLesson::where('is_active', true)->count());
        $this->assertEquals(30, $plan->fresh()->interest_percent);

        $case = TeachingCase::first();
        $this->assertSame('السلام عليكم', collect($case->history)->last()['text']);
        $this->assertStringContainsString('مساء الفل', $case->bad_reply);

        $data = $session->changes()->where('kind', 'data_update')->first();
        app(TeachingCoach::class)->approve($data, $staff->id);

        $this->assertEquals(33, $plan->fresh()->interest_percent);
        $this->assertSame('done', $session->fresh()->status);
        Queue::assertPushed(RunTeachingRegression::class);
    }

    public function test_an_edit_that_drops_existing_rules_is_blocked(): void
    {
        Queue::fake();
        $type = \App\Models\DocumentType::create(['key' => 'id_card', 'label' => 'بطاقة', 'extraction_fields' => ['full_name'], 'is_active' => true,
            'validation_rules' => [['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH']]]]);
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => false]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => 'sim-2', 'status' => 'open']);

        $plan = json_encode(['understanding' => 'x', 'question' => '', 'expectation' => '', 'operations' => [[
            'kind' => 'data_update', 'summary' => 'اقرا تاريخ الميلاد', 'entity' => 'document_type', 'record_id' => (string) $type->id,
            'changes' => [['field' => 'validation_rules', 'value' => json_encode([['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name']]])]],
        ]]]);
        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse([$plan], [], 'STOP', [], 'fake', null, 1));
        $fake->queue(new AiResponse([$plan], [], 'STOP', [], 'fake', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $session = app(TeachingCoach::class)->teach($conversation, null, 'اقرا تاريخ الميلاد', $staff->id);

        $this->assertCount(2, $fake->requests());
        $change = $session->changes()->first();
        $this->assertSame('invalid', $change->status);
        $this->assertStringContainsString('NAME_MISMATCH', $change->error);
    }

    public function test_simulator_and_teaching_pages_render_a_session(): void
    {
        $staff = Staff::create(['name' => 'A', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true]);
        $this->actingAs($staff);

        $sim = app(\App\Domain\Simulation\ConversationSimulator::class);
        $conversation = $sim->start('t');
        $sim->seed($conversation, 'incoming', 'السلام عليكم');
        $sim->seed($conversation, 'outgoing', 'مساء الفل');
        $target = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->max('id');
        $session = \App\Models\TeachingSession::create(['conversation_id' => $conversation->id, 'target_message_id' => $target, 'after_message_id' => $target,
            'owner_text' => 'الصح كذا', 'understanding' => 'فهمت إن السلام يترد', 'status' => 'awaiting_approval',
            'result' => ['checks' => [['label' => 'نفس الموقف', 'pass' => true, 'reply' => 'وعليكم السلام', 'reason' => '']]]]);
        TeachingChange::create(['teaching_session_id' => $session->id, 'kind' => 'data_update', 'target_type' => 'installment_plan', 'target_id' => (string) $this->plan()->id,
            'summary' => 'فايدة جديدة', 'after' => ['interest_percent' => 33], 'status' => 'proposed']);

        \Livewire\Livewire::test(\App\Filament\Pages\ConversationSimulatorPage::class, ['conversationId' => $conversation->id])
            ->set('teachMode', true)
            ->assertSee('فهمت إن السلام يترد')
            ->assertSee('فايدة جديدة')
            ->assertSee('موافق')
            ->assertSee('صحّح الرد ده');

        \Livewire\Livewire::test(\App\Filament\Resources\TeachingChangeResource\Pages\ListTeachingChanges::class)->assertSee('فايدة جديدة');
        \Livewire\Livewire::test(\App\Filament\Resources\BotLessonResource\Pages\ListBotLessons::class)->assertOk();
        \Livewire\Livewire::test(\App\Filament\Resources\TeachingCaseResource\Pages\ListTeachingCases::class)->assertOk();
    }

    public function test_the_coach_recovers_from_a_reply_stuck_on_blank_spaces(): void
    {
        Queue::fake();
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => false]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => 'sim-3', 'status' => 'open']);

        $fake = new FakeAiProvider();
        // Live 2026-09-26: the schema-constrained reply looped on spaces.
        $fake->queue(new AiResponse(['{"understanding": "فهمت", "expectation": "البوت يسأل'.str_repeat(' ', 500)], [], 'MAX_TOKENS', [], 'fake', null, 1));
        $fake->queue(new AiResponse(['```json'."\n".json_encode([
            'understanding' => 'فهمت إنه يسأل أنهي نسخة', 'question' => '', 'expectation' => 'يسأل أنهي نسخة',
            'operations' => [['kind' => 'instruction', 'summary' => 'قاعدة جديدة', 'find' => '', 'replace' => "## النسخ\n- لو الموديل ليه أكتر من نسخة اسأله أنهي.", 'changes' => []]],
        ], JSON_UNESCAPED_UNICODE)."\n```"], [], 'STOP', [], 'fake', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $runner = \Mockery::mock(RegressionRunner::class);
        $runner->shouldReceive('related')->andReturn(collect());
        $runner->shouldReceive('run')->andReturn(['pass' => true, 'reply' => 'تقصد أنهي نسخة؟', 'reason' => '']);
        $this->app->instance(RegressionRunner::class, $runner);

        $session = app(TeachingCoach::class)->teach($conversation, null, 'اسأله الأول أنهي هوجن 4', $staff->id);

        $this->assertSame('فهمت إنه يسأل أنهي نسخة', $session->understanding);
        $change = $session->changes()->first();
        $this->assertSame('proposed', $change->status);

        app(TeachingCoach::class)->approve($change, $staff->id);
        $this->assertStringEndsWith("## النسخ\n- لو الموديل ليه أكتر من نسخة اسأله أنهي.", app(\App\Domain\Settings\AgentInstructions::class)->current()['text']);
    }
}
