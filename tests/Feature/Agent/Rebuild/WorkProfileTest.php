<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordWorkProfileTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Applications\WorkClassification;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rebuild: the agent records the applicant's work once (record_work_profile);
 * the owner's rules read that record. No model is called from inside a tool.
 */
class WorkProfileTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function says(int $conversationId, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
    }

    private function ctx($conversation): ToolContext
    {
        return new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder());
    }

    public function test_his_work_is_recorded_from_his_own_words_and_the_rules_read_it_without_a_model(): void
    {
        // any model call would throw: the fake has nothing queued
        $this->app->instance(\App\Agent\Providers\AiProvider::class, new FakeAiProvider());
        $conversation = $this->conversation();
        $this->says($conversation->id, 'انا محاسب في شركة');

        $result = app(RecordWorkProfileTool::class)->execute([
            'evidence' => 'انا محاسب في شركة', 'occupation' => 'محاسب', 'work_stated' => true, 'customer_type' => 'unknown',
            'working_now' => 'yes', 'relation_to_workplace' => 'works_for_someone', 'insured' => 'unknown', 'question' => 'ask_insured',
        ], $this->ctx($conversation));

        $this->assertTrue($result->ok);
        $rules = app(WorkClassification::class);
        $this->assertSame('محاسب', $rules->reading($conversation->id)['occupation']);
        $this->assertSame('ASK_INSURED', $rules->problem($conversation->id, 'employee')['code']);
    }

    public function test_a_reading_with_words_he_never_wrote_is_refused(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'عايز اقسط هوجن');

        $result = app(RecordWorkProfileTool::class)->execute(['evidence' => 'انا موظف متأمن عليا', 'work_stated' => true, 'customer_type' => 'employee', 'working_now' => 'yes', 'insured' => 'yes'], $this->ctx($conversation));

        $this->assertSame('EVIDENCE_NOT_IN_HIS_MESSAGES', $result->error['code']);
        $this->assertNull(app(WorkClassification::class)->reading($conversation->id));
    }

    public function test_with_no_recorded_work_the_rules_ask_for_it_instead_of_guessing(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('WORK_NOT_RECORDED', app(WorkClassification::class)->problem($conversation->id, 'employee')['code']);
    }

    public function test_a_refused_occupation_is_refused_by_the_rule(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'انا امين شرطة');
        app(RecordWorkProfileTool::class)->execute(['evidence' => 'انا امين شرطة', 'occupation' => 'أمين شرطة', 'work_stated' => true,
            'customer_type' => 'employee', 'working_now' => 'yes', 'sector' => 'government', 'refused_work' => true], $this->ctx($conversation));

        $this->assertSame('OCCUPATION_NOT_ACCEPTED', app(WorkClassification::class)->problem($conversation->id, 'employee')['code']);
    }
}
