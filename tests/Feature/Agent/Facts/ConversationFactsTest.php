<?php

namespace Tests\Feature\Agent\Facts;

use App\Agent\Context\ContextBuilder;
use App\Agent\Context\Facts\ConversationFacts;
use App\Agent\Context\Facts\FactResolver;
use App\Agent\Context\Facts\ToldSoFar;
use App\Agent\Runtime\ReplyGuard;
use App\Domain\Conversations\QuotedOffer;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\CustomerType;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Agent\Rebuild\BuildsTurns;
use Tests\TestCase;

/** Phase 1: one deterministic, read-only factual view for the agent. */
class ConversationFactsTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function facts(WhatsappConversation $conversation, ?int $turnId = null): array
    {
        return app(ConversationFacts::class)->for($conversation->refresh(), $turnId)->facts;
    }

    private function machine(string $name = 'هوجن 3 استيراد كامل', int $price = 80000): Machine
    {
        $brand = Brand::firstOrCreate(['name' => 'هوجان'], ['image' => 'b.jpg']);

        return Machine::create(['name' => $name, 'brand_id' => $brand->id, 'cash_price' => $price, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
    }

    private function application(WhatsappConversation $conversation, array $extra = []): Application
    {
        $type = CustomerType::firstOrCreate(['key' => 'employee'], ['label' => 'موظف']);

        return Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting'] + $extra);
    }

    private function remember(Customer $customer, array $memory): void
    {
        Customer::whereKey($customer->id)->update(['memory' => json_encode($memory + ['facts' => [], 'motorcycles' => [], 'conversation' => []], JSON_UNESCAPED_UNICODE)]);
    }

    private function fact(string $value, string $source = 'customer_statement'): array
    {
        return ['value' => $value, 'source' => $source, 'at' => now()->toIso8601String()];
    }

    // ---------------------------------------------------------------- contradictory data

    public function test_profile_vs_application_the_application_wins_and_the_conflict_is_logged_not_shown(): void
    {
        $conversation = $this->conversation();
        RequirementField::create(['key' => 'full_name', 'label' => 'الاسم', 'data_type' => 'text', 'scope' => 'customer', 'is_sensitive' => false, 'is_active' => true]);
        CustomerAttribute::create(['customer_id' => $conversation->customer_id, 'field_key' => 'full_name', 'value' => 'محمد أحمد القديم', 'source' => 'customer_stated', 'status' => 'valid']);
        $application = $this->application($conversation);
        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'full_name', 'value' => 'محمد أحمد علي حسن', 'source' => 'document', 'status' => 'valid']);

        Log::spy();
        $result = app(ConversationFacts::class)->for($conversation->refresh());

        $this->assertSame('محمد أحمد علي حسن', $result->facts['person']['name']);
        $this->assertStringNotContainsString('القديم', json_encode($result->facts, JSON_UNESCAPED_UNICODE));
        $this->assertSame('person.name', $result->conflicts[0]['fact']);
        $this->assertSame(FactResolver::SNAPSHOT, $result->conflicts[0]['winner']['tier']);
        $this->assertSame(FactResolver::MEMORY, $result->conflicts[0]['loser']['tier']);
        Log::shouldHaveReceived('info')->with('agent.facts.conflict', \Mockery::on(fn ($c) => $c['conversation_id'] === $conversation->id))->once();
    }

    public function test_durable_memory_vs_the_current_conversation_the_conversation_wins(): void
    {
        $conversation = $this->conversation();
        $this->remember($conversation->customer, ['facts' => ['job' => $this->fact('سواق'), 'insured' => $this->fact('متأمن')]]);
        $conversation->update(['state' => ['work_profile' => [
            'work_stated' => true, 'occupation' => 'نجار', 'evidence' => 'بقيت نجار', 'insured' => 'no', 'customer_type' => 'self_employed', 'working_now' => 'yes',
        ]]]);
        $this->says($conversation, 'بقيت نجار');

        $facts = $this->facts($conversation);

        $this->assertSame('نجار', $facts['work']['occupation']);
        $this->assertSame('no', $facts['work']['insured']);
        $this->assertStringNotContainsString('سواق', json_encode($facts, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_free_text_memory_value_that_only_reads_differently_is_no_conflict(): void
    {
        $conversation = $this->conversation();
        $this->remember($conversation->customer, ['facts' => ['insured' => $this->fact('اه متأمن')]]);
        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'occupation' => 'موظف', 'evidence' => 'موظف', 'insured' => 'yes']]]);

        $this->assertSame([], app(ConversationFacts::class)->for($conversation->refresh())->conflicts);
    }

    public function test_facts_about_another_applicant_are_not_mixed_with_the_customers_own(): void
    {
        $conversation = $this->conversation();
        $this->remember($conversation->customer, ['facts' => ['name' => $this->fact('كيرلس'), 'age' => $this->fact('20')]]);
        $conversation->update(['state' => ['work_profile' => [
            'applicant' => 'someone_else', 'applicant_relation' => 'brother', 'applicant_quote' => 'اخويا',
            'work_stated' => true, 'occupation' => 'نجار', 'evidence' => 'شغال نجار', 'working_now' => 'yes',
        ]]]);

        $result = app(ConversationFacts::class)->for($conversation->refresh());

        $this->assertSame('أخوك', $result->facts['applicant']['person']);
        $this->assertArrayNotHasKey('name', $result->facts['person'] ?? []);
        $this->assertSame('كيرلس', $result->facts['customer']['himself']['name']);
        $this->assertSame([], $result->conflicts);
    }

    // ---------------------------------------------------------------- stale data and quotes

    public function test_a_valid_quote_is_the_only_source_of_a_price_and_drives_the_deal(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 24, 'monthly_payment' => 4321, 'cash_due_upfront' => 3000, 'total_paid' => 110000]);

        $facts = $this->facts($conversation);

        $this->assertSame(4321, $facts['quotes'][0]['monthly_payment']);
        $this->assertSame(['id' => $machine->id, 'name' => 'هوجن 3 استيراد كامل'], $facts['deal']['motorcycle']);
        $this->assertSame(24, $facts['deal']['financing_months']);
        $this->assertArrayNotHasKey('needs_new_lookup', $facts);
    }

    public function test_a_quote_older_than_24_hours_is_not_a_number_source_and_asks_for_a_new_lookup(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 24, 'monthly_payment' => 4321]);
        $this->travel(25)->hours();

        $facts = $this->facts($conversation);

        $this->assertArrayNotHasKey('quotes', $facts);
        $this->assertSame([['motorcycle' => 'هوجن 3 استيراد كامل', 'months' => 24, 'why' => 'older_than_24h']], $facts['needs_new_lookup']);
        $this->assertStringNotContainsString('4321', json_encode($facts));
        $this->assertArrayNotHasKey('financing_months', $facts['deal'] ?? []);
    }

    public function test_a_quote_whose_price_changed_since_is_expired_and_the_new_quote_replaces_it(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 24, 'monthly_payment' => 4321]);
        $this->travel(1)->minutes();
        $machine->update(['cash_price' => 90000]);

        $stale = $this->facts($conversation);
        $this->assertArrayNotHasKey('quotes', $stale);
        $this->assertSame('price_changed', $stale['needs_new_lookup'][0]['why']);

        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 24, 'monthly_payment' => 4800]);
        $current = $this->facts($conversation);

        $this->assertSame(4800, $current['quotes'][0]['monthly_payment']);
        $this->assertArrayNotHasKey('needs_new_lookup', $current);
        $this->assertStringNotContainsString('4321', json_encode($current));
    }

    public function test_the_newest_valid_quote_drives_the_deal_while_the_application_keeps_its_own_selection(): void
    {
        $conversation = $this->conversation();
        $first = $this->machine('بوكسر 150', 50000);
        $second = $this->machine('هوجن 4', 43000);
        $application = $this->application($conversation, ['machine_id' => $first->id]);
        QuotedOffer::addToLedger($conversation->id, $second->id, ['months' => 12, 'monthly_payment' => 4000]);

        $result = app(ConversationFacts::class)->for($conversation->refresh());

        $this->assertSame($second->id, $result->facts['deal']['motorcycle']['id']);
        $this->assertSame($first->id, $result->facts['application']['selection']['motorcycle']['id']);
        $this->assertSame($application->id, $result->facts['application']['id']);
        $this->assertSame('deal.motorcycle', $result->conflicts[0]['fact']);
    }

    public function test_memory_never_supplies_a_price_or_installment(): void
    {
        $conversation = $this->conversation();
        $this->remember($conversation->customer, ['facts' => ['monthly_budget' => $this->fact('3000'), 'preferred_duration' => $this->fact('سنتين')]]);

        $facts = $this->facts($conversation);

        $this->assertSame('3000', $facts['customer']['his_budget']['monthly']);
        $this->assertArrayNotHasKey('quotes', $facts);
        $this->assertArrayNotHasKey('financing_months', $facts['deal'] ?? []);
    }

    public function test_an_ai_guess_in_memory_is_not_a_fact(): void
    {
        $conversation = $this->conversation();
        $this->remember($conversation->customer, ['facts' => ['job' => $this->fact('مهندس', 'ai_inference'), 'governorate' => $this->fact('الجيزة')]]);

        $facts = $this->facts($conversation);

        $this->assertArrayNotHasKey('work', $facts);
        $this->assertSame('الجيزة', $facts['customer']['governorate']);
    }

    // ---------------------------------------------------------------- the summary is never a fact

    public function test_the_summary_changes_nothing_in_the_facts_and_a_price_only_in_it_is_not_sourced(): void
    {
        $conversation = $this->conversation();
        $before = $this->facts($conversation);
        $conversation->update(['summary' => json_encode(['facts' => ['اسمه محمود وسعر الهوجن 3 هو 77,777 جنيه'], 'decisions' => [], 'still_open' => []], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame($before, $this->facts($conversation));

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'بكام؟'))->system;
        $this->assertLessThan(mb_strpos($system, '## الكلام الأقدم'), mb_strpos($system, '## اللي نعرفه'));
        $this->assertStringContainsString('77,777', $system);

        // the guard must not accept a number that only the summary holds
        $this->assertSame('UNVERIFIED_NUMBER', app(ReplyGuard::class)->check(['messages' => ['الهوجن 3 بـ 77,777 جنيه']], $conversation, $system, [], []));
    }

    // ---------------------------------------------------------------- told so far

    public function test_told_so_far_is_built_from_the_ledger_the_trace_and_the_photos_we_sent(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $application = $this->application($conversation);
        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 24, 'monthly_payment' => 4321]);

        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        AiTraceStep::create(['trace_id' => $trace->id, 'turn_id' => 1, 'seq' => 1, 'kind' => 'tool_call', 'tool_name' => 'get_application_requirements',
            'permission' => 'READ', 'args_hash' => 'h1', 'args_redacted' => [], 'result_redacted' => ['ok' => true, 'data' => []]]);
        AiTraceStep::create(['trace_id' => $trace->id, 'turn_id' => 1, 'seq' => 2, 'kind' => 'tool_call', 'tool_name' => 'get_branch_information',
            'permission' => 'READ', 'args_hash' => 'h2', 'args_redacted' => [], 'result_redacted' => ['ok' => false], 'result_code' => 'NO_BRANCH']);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'image', 'metadata' => ['motorcycle_id' => $machine->id]]);

        $told = app(ToldSoFar::class)->for($conversation->refresh(), $application);

        $this->assertTrue($told['requirements_listed']);
        $this->assertSame(['هوجن 3 استيراد كامل'], $told['bike_photos_sent']);
        $this->assertArrayNotHasKey('branch_info_given', $told); // that lookup failed - nothing was told
        $this->assertStringNotContainsString('4321', json_encode($told)); // prices live in the facts' quotes, not repeated here
        $this->assertArrayNotHasKey('quoted', $told);
    }

    public function test_told_so_far_is_empty_when_nothing_happened_and_a_photo_that_failed_does_not_count(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'image',
            'delivery_status' => 'failed', 'metadata' => ['motorcycle_id' => $machine->id]]);

        $this->assertSame([], app(ToldSoFar::class)->for($conversation->refresh(), null));
    }

    // ---------------------------------------------------------------- recent messages

    public function test_messages_the_summary_has_not_absorbed_are_never_hidden_and_the_summary_reaches_up_to_the_last_n(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['agent.context.recent_messages_count' => 4, 'agent.summary.trigger_tokens' => null, 'agent.summary.trigger_messages' => 3]);
        $conversation = $this->conversation();

        foreach (range(1, 10) as $i) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => $i % 2 ? 'incoming' : 'outgoing', 'sender_type' => $i % 2 ? 'customer' : 'bot',
                'type' => 'text', 'text' => "رسالة {$i}", 'wa_message_id' => uniqid('wa')]);
        }

        $request = app(ContextBuilder::class)->build($this->turnFor($conversation, 'تمام'));

        // all ten are still in view: nothing has been summarized yet
        $this->assertCount(11, $request->contents);
        $fifth = WhatsappMessage::where('text', 'رسالة 7')->value('id'); // last 4 raw: 7..10
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SummarizeConversation::class, fn ($job) => $job->upToMessageId === $fifth);
    }

    // ---------------------------------------------------------------- read-only, deterministic, no scripted flow

    public function test_building_the_facts_twice_gives_identical_output_and_writes_nothing(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $application = $this->application($conversation, ['machine_id' => $machine->id]);
        QuotedOffer::addToLedger($conversation->id, $machine->id, ['months' => 12, 'monthly_payment' => 5000]);
        $conversation->update(['state' => array_merge($conversation->state ?? [], ['awaiting' => [['kind' => 'field', 'key' => 'phone']]])]);
        $conversation->refresh();

        $first = app(ConversationFacts::class)->for($conversation)->facts;

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $second = app(ConversationFacts::class)->for($conversation)->facts;

        $this->assertSame($first, $second);
        $this->assertSame([], $writes);
        $this->assertSame($application->id, $first['application']['id']);
    }

    public function test_no_scripted_flow_field_exists_anywhere_in_the_facts_or_the_prompt(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $application = $this->application($conversation, ['machine_id' => $machine->id]);
        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'occupation' => 'موظف', 'evidence' => 'موظف', 'question' => 'ask_insured', 'customer_type' => 'employee']]]);
        $this->says($conversation, 'موظف');

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'تمام'))->system;
        $json = json_encode($this->facts($conversation));

        foreach (['next_step', 'still_to_ask', 'full_list', 'full_list_sent', 'missing_hints', 'ask_together', 'both_sides', 'progress', 'intent', 'ask_for', 'next_question', 'current_step', 'skipped_after_two_asks'] as $banned) {
            $this->assertStringNotContainsString('"'.$banned.'"', $json, $banned);
            $this->assertStringNotContainsString('"'.$banned.'"', $system, $banned);
        }

        // nothing is configured as required here, so nothing is missing (and empty keys are omitted)
        $this->assertArrayNotHasKey('missing', $this->facts($conversation)['application']);
        $this->assertSame($application->id, $this->facts($conversation)['application']['id']);
    }

    public function test_the_record_your_work_reminder_sits_next_to_his_message_in_both_states(): void
    {
        $conversation = $this->conversation();
        $contents = fn () => app(ContextBuilder::class)->build($this->turnFor($conversation, 'لا مش متأمن'))->contents;
        $last = fn (array $c) => implode(' ', array_column(end($c)['parts'], 'text'));

        // nothing recorded yet: the reminder asks for the first record (and not to re-ask what he said)
        $first = $last($contents());
        $this->assertStringContainsString('record_work_profile', $first);
        $this->assertStringContainsString('متسألوش على حاجة قالها', $first);

        // something recorded: the reminder is about a change, and repeats no recorded value
        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'occupation' => 'موظف', 'evidence' => 'موظف', 'insured' => 'yes']]]);
        $text = $last($contents());

        $this->assertStringContainsString('بتغيّر مين اللي هيقدّم أو شغله', $text);
        $this->assertStringNotContainsString('موظف', str_replace('لا مش متأمن', '', preg_replace('/\\[ملاحظة.*\\]/u', '', $text))); // the note repeats no recorded value
    }

    public function test_a_customer_changing_his_answer_gives_the_latest_one(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'occupation' => 'سواق', 'evidence' => 'سواق', 'working_now' => 'yes', 'customer_type' => 'self_employed']]]);
        $this->assertSame('سواق', $this->facts($conversation)['work']['occupation']);

        $state = $conversation->refresh()->state;
        $state['work_profile'] = ['work_stated' => true, 'occupation' => 'موظف في شركة', 'evidence' => 'لا انا موظف', 'working_now' => 'yes', 'customer_type' => 'employee', 'insured' => 'yes'];
        $conversation->update(['state' => $state]);

        $work = $this->facts($conversation)['work'];
        $this->assertSame('موظف في شركة', $work['occupation']);
        $this->assertSame('employee', $work['customer_type']);
    }

    public function test_several_missing_fields_answered_at_once_all_leave_the_missing_list(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation);
        foreach (['phone' => 'phone', 'address' => 'text', 'work_address' => 'text'] as $key => $type) {
            $field = RequirementField::create(['key' => $key, 'label' => $key.' label', 'data_type' => $type, 'scope' => 'customer', 'is_sensitive' => false, 'is_active' => true]);
            \App\Models\ApplicationRequirement::create(['customer_type_id' => $application->customer_type_id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        }

        $missingKeys = fn () => array_column($this->facts($conversation)['application']['missing']['fields'] ?? [], 'key');
        $this->assertEqualsCanonicalizing(['phone', 'address', 'work_address'], $missingKeys());

        // one message gave all three; the tools recorded them
        foreach (['phone' => '01147709597', 'address' => 'شارع النيل 5', 'work_address' => 'ورشة الهرم'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }

        $this->assertSame([], $missingKeys());
        $this->assertCount(3, $this->facts($conversation)['application']['collected']);
    }

    public function test_the_resolver_ranks_by_authority_and_ignores_empty_candidates(): void
    {
        $resolver = new FactResolver();

        $result = $resolver->resolve('x', [
            ['tier' => FactResolver::MEMORY, 'value' => 'ذاكرة', 'source' => 'memory'],
            ['tier' => FactResolver::TOOL_EVIDENCE, 'value' => '', 'source' => 'quote'],
            ['tier' => FactResolver::SNAPSHOT, 'value' => 'طلب', 'source' => 'application'],
        ]);

        $this->assertSame('طلب', $result['value']);
        $this->assertSame(FactResolver::SNAPSHOT, $result['tier']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame([], $resolver->resolve('x', [['tier' => 1, 'value' => null, 'source' => 's']])['conflicts']);
    }

    private function says(WhatsappConversation $conversation, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text, 'wa_message_id' => uniqid('wa')]);
    }
}
