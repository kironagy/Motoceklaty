<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\AgentTurnProcessor;
use App\Agent\Runtime\ReplyGuard;
use App\Domain\Applications\SnapshotService;
use App\Domain\Conversations\ConversationClosing;
use App\Models\Application;
use App\Models\ApplicationRequirement;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-10-01 (conversation 729): a warehouse worker with no salary
 * slip was offered invented substitutes and "someone else applies in his
 * name", told "وقفتلك الطلب" with nothing stopped, and got eight goodbyes
 * to eight "تسلم / حبيبي".
 */
class SalarySlipAndClosingTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
    }

    private function check(string $reply): ?string
    {
        config(['agent.guard.number_min_value' => 1000]);

        return app(ReplyGuard::class)->check(['messages' => [$reply]], $this->conversation(), '', [], []);
    }

    private function employeeApplication(WhatsappConversation $conversation): Application
    {
        $customer = Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        $slip = DocumentType::create(['key' => 'salary_slip', 'label' => 'مفردات مرتب', 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'document', 'document_type_id' => $slip->id, 'is_required' => true]);

        return Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
    }

    public function test_an_employee_missing_the_salary_slip_is_told_what_to_do_without_it(): void
    {
        $application = $this->employeeApplication($this->conversation());

        $hint = app(SnapshotService::class)->for($application)['documents']['if_unavailable']['salary_slip'] ?? '';

        $this->assertStringContainsString('customer_type=self_employed', $hint);
        $this->assertStringContainsString('Never offer a substitute', $hint);
    }

    public function test_going_on_with_the_id_needs_the_switch_first(): void
    {
        $conversation = $this->conversation();
        $application = $this->employeeApplication($conversation);
        $check = fn (string $reply) => app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], []);

        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $check('مفيش مشكلة يا غالي، نكمّل بالبطاقة وعنوان شغلك.'));
        $this->assertNull($check('عشان نكمل، ابعتلي صورة وش وضهر البطاقة.'));

        // switched to self_employed: the slip is no longer on his list
        $application->update(['customer_type_id' => CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر'])->id]);
        $this->assertNull($check('مفيش مشكلة يا غالي، نكمّل بالبطاقة وعنوان شغلك.'));
    }

    public function test_invented_substitutes_for_the_salary_slip_are_blocked(): void
    {
        $this->assertSame('DOCUMENT_NOT_REQUIRED', $this->check('بما إن الشركة مش بتطلع مفردات، ممكن تبعتلي كشف حساب بنكي لآخر ٦ شهور؟'));
        $this->assertSame('DOCUMENT_NOT_REQUIRED', $this->check('ممكن تبعتلي صورة العقد ده؟ ده هيكون بديل ممتاز للمفردات.'));
    }

    public function test_someone_else_applying_in_his_name_is_blocked(): void
    {
        $this->assertSame('INVENTED_APPLICATION_ROUTE', $this->check('ممكن حضرتك تجيب أي شخص تاني (قريب أو صاحب) يكون متأمن عليه، ويقدم هو الطلب باسمه.'));
        $this->assertSame('INVENTED_APPLICATION_ROUTE', $this->check('تحب نجرّب نقدّم باسم حد تاني من طرفك؟'));
    }

    public function test_stopping_the_application_without_the_tool_is_blocked(): void
    {
        $this->assertSame('WITHDRAWAL_CLAIMED_NOT_DONE', $this->check('تمام يا باشا، وقفتلك الطلب دلوقتي.'));
        $this->assertSame('WITHDRAWAL_CLAIMED_NOT_DONE', $this->check('خلاص، هقفل الطلب دلوقتي زي ما اتفقنا.'));
    }

    public function test_sign_offs_and_bare_thanks_are_recognised(): void
    {
        $this->assertTrue(ConversationClosing::isSignOff('حبيبي يا غالي، نورتنا. في رعاية الله.'));
        $this->assertFalse(ConversationClosing::isSignOff('تمام يا باشا، نورتنا. تحب تشوف صور؟'));

        foreach (['تسلم', 'حبيبي', 'ماشى يا باشا', 'ماشى يا قلبي', 'الله يسلمك', 'شكرا 🙏', '👍'] as $text) {
            $this->assertTrue(ConversationClosing::isBareAcknowledgement($text), $text);
        }

        foreach (['مش هعرف', 'لا', 'انت مش عايز تمشي مع الدنيا', 'تمام ابعتلي الصور'] as $text) {
            $this->assertFalse(ConversationClosing::isBareAcknowledgement($text), $text);
        }
    }

    public function test_thanks_after_our_goodbye_gets_no_reply(): void
    {
        $conversation = $this->conversation();
        $say = fn (string $direction, string $sender, string $text, ?int $turn = null) => WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'direction' => $direction, 'sender_type' => $sender, 'type' => 'text', 'text' => $text, 'turn_id' => $turn,
        ]);

        $say('outgoing', 'bot', 'تسلم يا غالي، كلك ذوق. في رعاية الله.');
        $say('incoming', 'customer', 'حبيبي', 501);

        $this->assertSame(['messages' => []], app(AgentTurnProcessor::class)->process((object) ['id' => 501, 'whatsapp_conversation_id' => $conversation->id]));
        $this->assertTrue(ConversationClosing::onlyThanksAfterGoodbye($conversation->id, 501));

        // A real message after the goodbye still gets an answer.
        $say('incoming', 'customer', 'مش هعرف', 502);
        $this->assertFalse(ConversationClosing::onlyThanksAfterGoodbye($conversation->id, 502));

        // "تمام" answering our question is not a goodbye.
        $say('outgoing', 'bot', 'دي القسط والمصاريف، تمام؟');
        $say('incoming', 'customer', 'تمام', 503);
        $this->assertFalse(ConversationClosing::onlyThanksAfterGoodbye($conversation->id, 503));
    }

    public function test_a_job_said_over_several_messages_is_found(): void
    {
        $conversation = $this->conversation();
        foreach (['او مكنه f250', 'انا شغال شيف', 'ف مجال السياحه وكدا', 'بس اخويا موظف', 'حكومه', 'متامن', 'عليه'] as $text) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
        }
        $statements = new \App\Domain\Conversations\CustomerStatements();

        $this->assertNotNull($statements->messageContainingQuote($conversation->id, 'شغال شيفف مجال السياحه'));
        $this->assertNotNull($statements->messageContainingQuote($conversation->id, 'حكومهمتامنعليه'));
        $this->assertNull($statements->messageContainingQuote($conversation->id, 'شغال نجار في ورشة'));
    }
}
