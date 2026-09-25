<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Domain\Settings\AgentInstructions;
use App\Domain\Settings\AgentSettings;
use App\Domain\Simulation\ConversationSimulator;
use App\Models\AgentInstructionVersion;
use App\Models\AiTrace;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotDashboardControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.runtime.max_model_calls' => 6,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
        ]);
    }

    public function test_a_dashboard_setting_overrides_config_and_clearing_it_restores_the_env_value(): void
    {
        AgentSettings::apply();
        $envValue = config('agent.reply.max_chars');

        AgentSettings::save(['reply.max_chars' => '450', 'model' => 'gemini-dashboard']);

        $this->assertSame(450, config('agent.reply.max_chars'));
        $this->assertSame('gemini-dashboard', config('agent.model'));

        AgentSettings::save(['reply.max_chars' => '']);

        $this->assertSame($envValue, config('agent.reply.max_chars'));
        $this->assertSame('gemini-dashboard', config('agent.model'));
    }

    public function test_the_bot_can_be_switched_off_from_the_dashboard(): void
    {
        AgentSettings::save(['enabled' => '0']);

        $this->assertFalse(config('agent.enabled'));
    }

    public function test_fallback_models_are_stored_as_the_comma_list_the_provider_reads(): void
    {
        AgentSettings::save(['fallback_models' => ' a-model , b-model ,, ']);

        $this->assertSame('a-model,b-model', config('agent.fallback_models'));
    }

    public function test_instructions_come_from_the_file_until_a_dashboard_version_is_published(): void
    {
        $instructions = app(AgentInstructions::class);
        $file = $instructions->fromFile();

        $this->assertSame($file['version'], $instructions->current()['version']);
        $this->assertSame('file', $instructions->current()['source']);

        $expected = $instructions->nextVersion($file['version']);
        $published = $instructions->publish("أنت بياع موتوسيكلات.\nرد باختصار.", 'تجربة');

        $this->assertSame($expected, $published->version);
        $this->assertSame('dashboard', $instructions->current()['source']);
        $this->assertSame("أنت بياع موتوسيكلات.\nرد باختصار.", $instructions->current()['text']);

        $second = $instructions->publish('نسخة تانية');
        $this->assertNotSame($published->version, $second->version);
        $this->assertSame(1, AgentInstructionVersion::where('is_active', true)->count());

        $instructions->activate($published);
        $this->assertSame($published->version, $instructions->current()['version']);

        $instructions->useFile();
        $this->assertSame('file', $instructions->current()['source']);
    }

    public function test_the_simulator_runs_the_real_agent_and_sends_nothing_to_whatsapp(): void
    {
        Http::fake();

        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);
        $fake->queue(new AiResponse([], [['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['أهلاً بيك، تحت أمرك']]]], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 10));

        $published = app(AgentInstructions::class)->publish('تعليمات من لوحة التحكم');

        $simulator = app(ConversationSimulator::class);
        $conversation = $simulator->start('test');
        $result = $simulator->send($conversation, 'السلام عليكم');

        $this->assertNull($result['error']);
        $this->assertSame(['أهلاً بيك، تحت أمرك'], $result['reply']);
        $this->assertFalse((bool) $simulator->bot()->is_active);

        $this->assertSame(1, WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('delivery_status', 'sent')->count());

        // the dashboard instructions were the ones sent to the model
        $this->assertStringContainsString('تعليمات من لوحة التحكم', $fake->lastRequest()->system);
        $this->assertSame($published->version, AiTrace::where('conversation_id', $conversation->id)->value('prompt_version'));

        Http::assertNothingSent();
    }

    public function test_the_simulator_page_shows_the_reply_and_what_the_bot_did(): void
    {
        Http::fake();

        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);
        $fake->queue(new AiResponse([], [['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['الهوجن 4 موجودة']]]], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 10));

        $admin = \App\Models\Staff::create(['name' => 'A', 'email' => 'admin@x.test', 'password' => 'secret', 'is_admin' => true]);
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Pages\ConversationSimulatorPage::class)
            ->call('quick', 'بكام الهوجن 4؟')
            ->assertSee('بكام الهوجن 4؟')
            ->assertSee('الهوجن 4 موجودة')
            ->assertSee('إيه اللي البوت عمله');

        Http::assertNothingSent();
    }

    public function test_the_settings_and_instructions_pages_save(): void
    {
        $admin = \App\Models\Staff::create(['name' => 'A', 'email' => 'admin2@x.test', 'password' => 'secret', 'is_admin' => true]);
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Pages\BotSettings::class)
            ->set('data.reply__max_chars', '500')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(500, config('agent.reply.max_chars'));

        \Livewire\Livewire::test(\App\Filament\Pages\BotInstructions::class)
            ->set('data.content', 'تعليمات جديدة للتجربة')
            ->set('data.notes', 'تجربة')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame('تعليمات جديدة للتجربة', app(AgentInstructions::class)->current()['text']);
    }

    public function test_each_simulated_turn_sees_the_customers_newest_message(): void
    {
        Http::fake();

        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);
        $reply = fn (string $text) => new AiResponse([], [['id' => uniqid(), 'name' => 'send_reply', 'args' => ['messages' => [$text]]]], 'STOP', ['input_tokens' => 1, 'output_tokens' => 1], 'gemini-test', null, 1);

        $simulator = app(ConversationSimulator::class);
        $conversation = $simulator->start();

        $fake->queue($reply('أهلاً'));
        $simulator->send($conversation, 'السلام عليكم');

        // the second turn's services must not reuse the first turn's message cache
        $fake->queue($reply('تمام'));
        $simulator->send($conversation, 'رقمي 01012345678');

        $statements = app(\App\Domain\Conversations\CustomerStatements::class);
        $this->assertNotNull($statements->messageContainingValue($conversation->id, '01012345678', 'phone'));
        $this->assertCount(2, $fake->requests());
    }

    public function test_the_simulator_stays_silent_after_a_handoff_like_whatsapp(): void
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        $simulator = app(ConversationSimulator::class);
        $conversation = $simulator->start();
        $conversation->update(['status' => 'awaiting_agent']);

        $result = $simulator->send($conversation, 'انا عايز فلوسي');

        $this->assertTrue($result['handed_off']);
        $this->assertSame([], $fake->requests());

        $simulator->returnToBot($conversation);
        $this->assertSame('open', $conversation->fresh()->status);
    }
}
