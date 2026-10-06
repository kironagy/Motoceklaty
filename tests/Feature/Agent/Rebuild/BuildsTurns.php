<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Models\Customer;
use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;

/** Shared fixtures for the rebuild (v2) tests. */
trait BuildsTurns
{
    protected function runtimeLimits(): void
    {
        config([
            'agent.runtime.max_model_calls' => 5,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
            'agent.fallback.message' => 'حصل عطل بسيط',
            'agent.handoff.max_failed_turns' => 2,
        ]);
    }

    protected function conversation(string $phone = '2011'): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => $phone, 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => $phone.'@s.whatsapp.net', 'phone' => $phone]);
        $conversation->update(['customer_id' => $customer->id]);

        return $conversation->refresh();
    }

    protected function turnFor(WhatsappConversation $conversation, ?string $text = null): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($text !== null) {
            WhatsappMessage::create([
                'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
                'whatsapp_conversation_id' => $conversation->id,
                'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text',
                'text' => $text, 'turn_id' => $id, 'wa_message_id' => uniqid('wa'),
            ]);
        }

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    protected function fakeAi(): FakeAiProvider
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    protected function reply(array $toolCalls = [], array $textParts = []): AiResponse
    {
        return new AiResponse($textParts, $toolCalls, 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 12);
    }

    protected function sendReply(string ...$messages): AiResponse
    {
        return $this->reply([['id' => uniqid('c'), 'name' => 'send_reply', 'args' => ['messages' => $messages]]]);
    }

    protected function keyModel(string $provider = 'gemini', string $modelCode = 'gemini-test', string $name = 'Key A', int $priority = 1): GeminiApiKeyModel
    {
        $apiKey = GeminiApiKey::query()->create(['provider' => $provider, 'name' => $name, 'api_key' => 'test-'.$name, 'is_active' => true]);

        return $apiKey->models()->create([
            'provider' => $provider, 'display_name' => $modelCode, 'model_code' => $modelCode, 'category' => 'x',
            'rpm_limit' => 15, 'rpd_limit' => 500, 'tps_limit' => 1000000, 'requests_today' => 0,
            'requests_this_minute' => 0, 'tokens_this_second' => 0, 'is_active' => true, 'is_embedding' => false, 'priority' => $priority,
        ]);
    }
}
