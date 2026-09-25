<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AiTraceResource\Pages\ViewAiTrace;
use App\Filament\Resources\ApplicationResource\Pages\ViewApplication;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-09-26: trace and application pages crashed on production data. */
class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true]);
        $this->actingAs($staff);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'customer_id' => $customer->id, 'phone' => '2011', 'status' => 'open']);
    }

    public function test_a_trace_with_held_back_replies_renders(): void
    {
        $conversation = $this->conversation();
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'fallback',
            'context_manifest' => ['usage' => ['model_calls' => 2, 'input_tokens' => 100]],
            'guard_events' => [['code' => 'UNVERIFIED_NUMBER', 'args' => ['messages' => ['السعر 50,000']]]]]);

        Livewire::test(ViewAiTrace::class, ['record' => $trace->id])
            ->assertOk()
            ->assertSee('فيه رقم مش موجود في بيانات السيستم')
            ->assertSee('السعر 50,000')
            ->assertSee('رد احتياطي');
    }

    public function test_an_application_with_an_unreadable_document_renders_in_arabic(): void
    {
        $conversation = $this->conversation();
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر'])->id, 'status' => 'collecting']);
        $message = \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = \App\Models\MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'x.jpg', 'size' => 1, 'sha256' => uniqid()]);
        // written under another APP_KEY
        DB::table('application_documents')->insert(['application_id' => $application->id, 'media_id' => $media->id, 'party' => 'applicant', 'status' => 'rejected',
            'detected_type_key' => 'national_id_front', 'extracted' => 'not-decryptable', 'issues' => json_encode([['code' => 'BLURRY_DOCUMENT']]),
            'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(ViewApplication::class, ['record' => $application->id])
            ->assertOk()
            ->assertSee('بيجمع البيانات')
            ->assertSee('الصورة مش واضحة')
            ->assertSee('مش قادر يتقري')
            ->assertDontSee('blockers.0');
    }

    public function test_saving_a_document_type_keeps_how_names_are_matched(): void
    {
        $this->conversation();
        auth()->user()->forceFill(['is_bot' => true, 'is_hitler' => true])->save();
        $type = \App\Models\DocumentType::create(['key' => 'salary_slip', 'label' => 'مفردات المرتب', 'description_for_ai' => 'x',
            'extraction_fields' => ['full_name'], 'optional_fields' => ['hire_date'], 'is_active' => true,
            'validation_rules' => [['rule_type' => 'matches_application_field', 'params' => [
                'extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH', 'match' => 'name_tokens',
            ]]]]);
        \App\Models\RequirementField::create(['key' => 'full_name', 'label' => 'الاسم', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);

        Livewire::test(\App\Filament\Resources\DocumentTypeResource\Pages\EditDocumentType::class, ['record' => $type->id])
            ->assertSee('حقول بتتقري لو موجودة')
            ->call('save')
            ->assertHasNoErrors();

        $type->refresh();
        $this->assertSame('name_tokens', $type->validation_rules[0]['params']['match']);
        $this->assertSame(['hire_date'], $type->optional_fields);
    }
}
