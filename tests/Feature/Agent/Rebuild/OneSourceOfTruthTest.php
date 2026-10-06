<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Domain\Memory\CustomerMemory;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\BotLesson;
use App\Models\BusinessMemory;
use App\Models\CustomerType;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild: one instruction source, one value per fact, the document beats his words. */
class OneSourceOfTruthTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    public function test_his_words_against_a_document_show_once_as_a_conflict_the_document_wins(): void
    {
        $conversation = $this->conversation();
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'مرتبي 12000']);
        app(CustomerMemory::class)->applyModelUpdate($conversation->customer_id, $conversation->id, ['facts' => [['key' => 'monthly_income', 'value' => '12000', 'quote' => 'مرتبي 12000']]]);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'is_active' => true]);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        ApplicationData::create(['application_id' => $application->id, 'field_key' => 'monthly_income', 'party' => 'applicant', 'value' => '15000', 'source' => 'document', 'status' => 'valid']);

        $prompt = app(CustomerMemory::class)->forPrompt($conversation->customer_id, $conversation->id, $application->id);

        $this->assertStringContainsString('قال "12000" والمستند فيه "15000" - المستند هو الصح', $prompt);
        $this->assertStringNotContainsString('قاله بنفسه: الدخل الشهري: 12000', $prompt);
    }

    public function test_dashboard_knowledge_is_not_a_second_instruction_source_and_lessons_live_in_the_instructions(): void
    {
        BusinessMemory::create(['key' => 'k', 'category' => 'general', 'title' => 'قاعدة قديمة', 'content' => 'محتوى مثبت', 'priority' => 1, 'is_pinned' => true, 'is_active' => true]);
        BotLesson::create(['title' => 'الصور', 'rule' => 'ما تبعتش صور غير لو طلبها', 'priority' => 100, 'is_active' => true, 'revision' => 1]);
        $conversation = $this->conversation();

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'السلام عليكم'))->system;

        $this->assertStringNotContainsString('محتوى مثبت', $system);
        $this->assertSame(1, substr_count($system, 'ما تبعتش صور غير لو طلبها'));
        $this->assertStringContainsString(\App\Domain\Teaching\LessonSync::HEADING, $system);
    }

    public function test_a_switched_off_lesson_leaves_the_instructions(): void
    {
        $lesson = BotLesson::create(['title' => 'الصور', 'rule' => 'ما تبعتش صور غير لو طلبها', 'priority' => 100, 'is_active' => true, 'revision' => 1]);
        $lesson->update(['is_active' => false]);

        $this->assertStringNotContainsString('ما تبعتش صور', app(\App\Domain\Settings\AgentInstructions::class)->current()['text']);
    }
}
