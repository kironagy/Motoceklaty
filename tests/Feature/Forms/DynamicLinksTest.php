<?php

namespace Tests\Feature\Forms;

use App\Agent\Tools\SendApplicationLinkTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\ToolRegistry;
use App\Agent\Runtime\TurnResultBuilder;
use App\Domain\Forms\FormLinkFollowUpService;
use App\Domain\Forms\FormLinkService;
use App\Domain\Forms\FormPublisher;
use App\Domain\Forms\FormSubmissionService;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DynamicForm;
use App\Models\FormLink;
use App\Models\FormLinkFile;
use App\Models\InstallmentPlan;
use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DynamicLinksTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private WhatsappBot $bot;

    private WhatsappConversation $conversation;

    private Machine $machine;

    private InstallmentPlan $plan;

    private CustomerType $employee;

    private CustomerType $freelancer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 12:00:00');
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'id' => 'wamid.X'], 200)]);

        config([
            'agent.enabled' => true,
            'agent.applications.mode' => 'form_links',
            'services.whatsapp.bot_token' => 'test-token',
            'services.whatsapp.worker_url' => 'http://worker.test',
            'app.url' => 'https://motogate-eg.com',
        ]);

        $this->staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $this->bot = WhatsappBot::create(['staff_id' => $this->staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $customer = Customer::create(['whatsapp_bot_id' => $this->bot->id, 'jid' => '201012345678@s.whatsapp.net', 'phone' => '201012345678']);
        $this->conversation = WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'customer_id' => $customer->id, 'phone' => '201012345678', 'status' => 'open']);

        $this->employee = CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'legacy_work_status' => 'employee']);
        $this->freelancer = CustomerType::create(['key' => 'self_employed', 'label' => 'شغل حر', 'legacy_work_status' => 'no_income_proof']);

        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $this->machine = Machine::create([
            'name' => 'Boxer', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal',
        ]);
        $system = \App\Models\InstallmentSystem::create([
            'name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7,
        ]);
        $this->machine->update(['installment_systems' => [$system->id]]);
        $this->plan = InstallmentPlan::where('installment_system_id', $system->id)->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A form like the owner's first one: name, id, phone, work (decides the type), salary only for employees, address, ID photo, machine, plan. */
    private function form(array $extraItems = []): DynamicForm
    {
        $form = DynamicForm::create(['name' => 'طلب تقسيط', 'is_bot_default' => true, 'schema' => ['sections' => [
            ['key' => 's1', 'title' => 'بياناتك', 'items' => array_merge([
                ['type' => 'text', 'data' => ['key' => 'name', 'label' => 'الاسم', 'required' => true, 'binding' => 'applicant.name', 'rules' => ['min_length' => 6]]],
                ['type' => 'national_id', 'data' => ['key' => 'nid', 'label' => 'الرقم القومي', 'required' => true, 'binding' => 'applicant.national_id', 'rules' => ['max_age' => 62]]],
                ['type' => 'phone', 'data' => ['key' => 'phone', 'label' => 'الموبايل', 'required' => true, 'binding' => 'applicant.phone']],
                ['type' => 'radio', 'data' => ['key' => 'work', 'label' => 'شغلك', 'required' => true, 'binding' => 'work.status', 'options' => [
                    ['value' => 'employee', 'label' => 'موظف', 'customer_type_id' => $this->employee->id],
                    ['value' => 'free', 'label' => 'شغل حر', 'customer_type_id' => $this->freelancer->id],
                ]]],
                ['type' => 'money', 'data' => ['key' => 'salary', 'label' => 'المرتب', 'required' => true, 'binding' => 'work.salary_amount',
                    'conditions' => [['field' => 'work', 'operator' => 'equals', 'value' => 'employee']]]],
                ['type' => 'address', 'data' => ['key' => 'home', 'label' => 'العنوان', 'required' => true, 'binding' => 'applicant.address']],
                ['type' => 'text', 'data' => ['key' => 'job_years', 'label' => 'سنين الشغل', 'required' => false]],
            ], $extraItems)],
            ['key' => 's2', 'title' => 'المكنة والورق', 'items' => [
                ['type' => 'machine', 'data' => ['key' => 'machine', 'label' => 'المكنة', 'required' => true, 'binding' => 'request.machine']],
                ['type' => 'plan', 'data' => ['key' => 'plan', 'label' => 'النظام', 'required' => true, 'binding' => 'request.plan']],
                ['type' => 'document', 'data' => ['key' => 'id_front', 'label' => 'وش البطاقة', 'required' => true, 'binding' => 'applicant.id_front']],
            ]],
        ]]]);

        $this->assertSame([], app(FormPublisher::class)->publish($form, $this->staff->id));

        return $form->refresh();
    }

    private function link(array $prefill = []): FormLink
    {
        $this->form();

        return app(FormLinkService::class)->linkFor($this->conversation, $prefill);
    }

    private function answer(FormLink $link, string $key, mixed $value)
    {
        return $this->postJson('/f/'.$link->token.'/answer', ['key' => $key, 'value' => $value]);
    }

    private function fillValid(FormLink $link): void
    {
        $this->answer($link, 'name', 'محمد علي حسن');
        $this->answer($link, 'nid', '29001011234567');
        $this->answer($link, 'phone', '01012345678');
        $this->answer($link, 'work', 'employee');
        $this->answer($link, 'salary', '٨٠٠٠');
        $this->answer($link, 'home', ['governorate' => 'الجيزة', 'area' => 'فيصل', 'street' => 'شارع العشرين', 'building' => '12', 'landmark' => 'جنب المسجد']);
        $this->answer($link, 'machine', $this->machine->id);
        $this->answer($link, 'plan', $this->plan->id);
        $this->uploadIdFront($link, 'ok');
    }

    private function uploadIdFront(FormLink $link, string $checkStatus): FormLinkFile
    {
        $this->postJson('/f/'.$link->token.'/files', ['key' => 'id_front', 'file' => UploadedFile::fake()->image('id.jpg', 800, 500)])
            ->assertOk()->assertJsonPath('ok', true);

        $file = FormLinkFile::where('form_link_id', $link->id)->latest('id')->first();
        $file->update(['check_status' => $checkStatus, 'message' => $checkStatus === 'warning' ? 'الصورة مش واضحة' : null]);

        return $file;
    }

    public function test_every_answer_is_saved_as_typed_and_the_link_shows_how_far_he_got(): void
    {
        $link = $this->link();

        $this->answer($link, 'name', 'محمد علي حسن')->assertOk()->assertJsonPath('error', null);
        $this->answer($link, 'work', 'free')->assertOk();

        $link->refresh();
        $this->assertSame('in_progress', $link->status);
        $this->assertSame('محمد علي حسن', $link->answerValues()['name']);

        $progress = app(FormLinkService::class)->progress($link);
        $this->assertSame(2, $progress['done']);
        // salary is hidden for a freelancer, so it is not missing
        $this->assertNotContains('المرتب', $progress['missing']);
        $this->assertContains('وش البطاقة', $progress['missing']);
    }

    public function test_the_owners_rules_judge_each_answer(): void
    {
        $link = $this->link();

        $this->answer($link, 'name', 'محمد')->assertJsonPath('error', 'لازم يكون 6 حرف على الأقل');
        $this->answer($link, 'phone', '0101234')->assertJsonPath('error', 'اكتب رقم موبايل مصري صحيح (11 رقم يبدأ بـ 01)');
        // born 1950: over 62
        $this->answer($link, 'nid', '25001011234567')->assertJsonPath('error', 'السن لازم ميزيدش عن 62 سنة');
        $this->answer($link, 'nid', '29001011234567')->assertJsonPath('error', null);
    }

    public function test_opening_the_page_is_not_counted_until_the_page_itself_pings(): void
    {
        $link = $this->link();

        // WhatsApp's link preview fetches the page without running it
        $this->get('/f/'.$link->token)->assertOk()->assertSee('طلب تقسيط');
        $this->assertSame('sent', $link->refresh()->status);

        $this->postJson('/f/'.$link->token.'/ping')->assertOk();
        $this->assertSame('opened', $link->refresh()->status);
        $this->assertNotNull($link->first_opened_at);
    }

    public function test_a_full_form_becomes_the_same_request_a_bot_application_makes_and_he_gets_the_receipt(): void
    {
        $link = $this->link();
        $this->fillValid($link);

        $this->postJson('/f/'.$link->token.'/submit')->assertOk()->assertJsonPath('ok', true);

        $request = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();
        $this->assertSame('new_request', $request->status);
        $this->assertSame('bot', $request->request_type);
        $this->assertSame($this->staff->id, $request->staff_id);
        $this->assertSame('محمد علي حسن', $request->applicant_name);
        $this->assertSame('29001011234567', $request->applicant_national_id);
        $this->assertSame('1990-01-01', $request->applicant_birthdate->toDateString());
        $this->assertSame('01012345678', $request->applicant_phone);
        $this->assertSame('employee', $request->work_status);
        $this->assertEquals(8000, $request->salary_amount);
        $this->assertSame('الجيزة', $request->applicant_governorate);
        $this->assertSame('شارع العشرين', $request->applicant_street);
        $this->assertSame('12', $request->applicant_building_number);
        $this->assertSame($this->machine->id, $request->machine_id);
        $this->assertSame(12, (int) $request->months);
        $this->assertNotNull($request->applicant_id_image);
        Storage::disk('public')->assertExists($request->applicant_id_image);

        $application = Application::findOrFail($request->application_id);
        $this->assertSame('submitted', $application->status);
        $this->assertSame($this->employee->id, $application->customer_type_id);
        $this->assertSame(1, ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')->count());

        $this->assertSame('submitted', $link->refresh()->status);
        $this->assertTrue(WhatsappMessage::where('whatsapp_conversation_id', $this->conversation->id)
            ->where('direction', 'outgoing')->where('text', 'like', '%استلمنا طلبك بنجاح%')->exists());
    }

    public function test_a_hidden_required_field_never_blocks_and_a_visible_empty_one_does(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        $this->answer($link, 'work', 'free');
        $this->answer($link, 'salary', '');

        // freelancer: salary hidden, not required
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        $this->assertSame('no_income_proof', InstallmentRequest::where('form_link_id', $link->id)->value('work_status'));
        $this->assertNull(InstallmentRequest::where('form_link_id', $link->id)->value('salary_amount'));
    }

    public function test_missing_answers_come_back_by_field_and_nothing_is_sent(): void
    {
        $link = $this->link();
        $this->answer($link, 'name', 'محمد علي حسن');
        $this->answer($link, 'work', 'employee');

        $this->postJson('/f/'.$link->token.'/submit')->assertStatus(422)
            ->assertJsonPath('errors.salary', 'مطلوب')
            ->assertJsonPath('errors.id_front', 'ارفع وش البطاقة');

        $this->assertSame(0, InstallmentRequest::count());
    }

    public function test_a_stop_message_the_owner_set_stops_the_submit(): void
    {
        $form = DynamicForm::query()->first() ?? null;
        $this->form([
            ['type' => 'yes_no', 'data' => ['key' => 'foreigner', 'label' => 'أجنبي؟', 'required' => true]],
            ['type' => 'stop', 'data' => ['key' => 'no_foreigners', 'message' => 'للأسف التقسيط للمصريين بس', 'conditions' => [['field' => 'foreigner', 'operator' => 'equals', 'value' => 'yes']]]],
        ]);
        $link = app(FormLinkService::class)->linkFor($this->conversation);
        $this->fillValid($link);
        $this->answer($link, 'foreigner', 'yes');

        $this->postJson('/f/'.$link->token.'/submit')->assertStatus(422)->assertJsonPath('blocking.0', 'للأسف التقسيط للمصريين بس');

        $this->answer($link, 'foreigner', 'no');
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
    }

    public function test_the_same_identity_cannot_create_a_second_request_even_after_a_decision(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        $first = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();

        $other = WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'phone' => '201099999999', 'status' => 'open',
            'customer_id' => Customer::create(['whatsapp_bot_id' => $this->bot->id, 'jid' => '201099999999@s.whatsapp.net', 'phone' => '201099999999'])->id]);
        $second = app(FormLinkService::class)->linkFor($other);
        $this->fillValid($second);

        $this->postJson('/f/'.$second->token.'/submit')->assertStatus(422)->assertJsonPath('code', 'DUPLICATE');

        // Current owner policy: reuse the existing identity record across staff, including decided requests.
        $first->forceFill(['status' => 'rejected'])->saveQuietly();
        $this->postJson('/f/'.$second->token.'/submit')->assertStatus(422)->assertJsonPath('code','DUPLICATE');
        $this->assertSame(1, InstallmentRequest::where('applicant_phone', '01012345678')->count());
    }

    public function test_sending_twice_makes_one_request(): void
    {
        $link = $this->link();
        $this->fillValid($link);

        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        $this->assertTrue(app(FormSubmissionService::class)->submit($link->refresh())['ok']);

        $this->assertSame(1, InstallmentRequest::count());
    }

    public function test_asking_again_gives_the_same_link_and_the_bot_only_fills_empty_fields(): void
    {
        $link = $this->link(['customer_name' => 'محمد علي حسن', 'machine_id' => $this->machine->id, 'months' => 12]);

        $this->assertSame($this->machine->id, $link->answerValues()['machine']);
        $this->assertSame($this->plan->id, $link->answerValues()['plan']);

        $this->answer($link, 'name', 'أحمد سامي فؤاد');
        $again = app(FormLinkService::class)->linkFor($this->conversation, ['customer_name' => 'اسم تاني خالص']);

        $this->assertSame($link->id, $again->id);
        $this->assertSame('أحمد سامي فؤاد', $again->answerValues()['name']);
    }

    public function test_an_expired_link_is_renewed_with_everything_he_wrote(): void
    {
        $link = $this->link();
        $this->answer($link, 'name', 'محمد علي حسن');
        $this->uploadIdFront($link, 'ok');

        Carbon::setTestNow(now()->addDays(8));
        $this->get('/f/'.$link->token)->assertSee('الرابط ده مبقاش شغال');

        $renewed = app(FormLinkService::class)->linkFor($this->conversation);

        $this->assertNotSame($link->id, $renewed->id);
        $this->assertSame('محمد علي حسن', $renewed->answerValues()['name']);
        $this->assertSame(1, $renewed->files()->count());
        $this->assertSame('expired', $link->refresh()->status);
    }

    public function test_a_document_the_ai_warned_about_still_reaches_staff_in_its_place_with_a_flag(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        FormLinkFile::where('form_link_id', $link->id)->update(['check_status' => 'warning', 'message' => 'الصورة مش واضحة']);

        $this->postJson('/f/'.$link->token.'/submit')->assertOk();

        $request = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();
        $this->assertNotNull($request->applicant_id_image);
        $this->assertSame('needs_review', ApplicationDocument::where('application_id', $request->application_id)->value('status'));
        $extras = app(\App\Domain\Forms\FormRequestExtras::class)->for($request);
        $this->assertSame('الصورة مش واضحة', $extras['warnings'][0]['message']);
    }

    public function test_answers_tied_to_nothing_show_on_the_request_as_extra_data(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        $this->answer($link, 'job_years', '5 سنين');
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();

        $extras = app(\App\Domain\Forms\FormRequestExtras::class)->for(InstallmentRequest::where('form_link_id', $link->id)->first());
        $this->assertSame(['سنين الشغل' => '5 سنين'], $extras['answers']);
    }

    public function test_follow_up_waits_while_he_is_filling_and_says_what_is_left(): void
    {
        $link = $this->link();
        $followUps = app(FormLinkFollowUpService::class);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->answer($link, 'name', 'محمد علي حسن');
        Carbon::setTestNow(now()->addMinutes(10));
        // 10 minutes since his last answer: still filling
        $this->assertSame(0, $followUps->run());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(1, $followUps->run());
        $text = WhatsappMessage::where('whatsapp_conversation_id', $this->conversation->id)->where('direction', 'outgoing')->latest('id')->value('text');
        $this->assertStringContainsString('الرقم القومي', $text);
        $this->assertStringContainsString($link->url(), $text);

        // the second one only a day later, then never again
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, $followUps->run());
        Carbon::setTestNow(now()->addHours(23));
        $this->assertSame(1, $followUps->run());
        Carbon::setTestNow(now()->addDays(2)->setTime(12, 0));
        $this->assertSame(0, $followUps->run());
    }

    public function test_follow_up_never_at_night_and_not_after_he_sent_it(): void
    {
        $link = $this->link();

        Carbon::setTestNow('2026-10-18 23:30:00');
        $this->assertSame(0, app(FormLinkFollowUpService::class)->run());

        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->fillValid($link);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        Carbon::setTestNow('2026-10-18 13:00:00');
        $this->assertSame(0, app(FormLinkFollowUpService::class)->run());
    }

    public function test_staff_asking_for_a_data_fix_reopens_the_form_and_its_submit_returns_the_same_request(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        $request = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();
        $request->update(['status' => 'pending']);

        // staff pause it: "fix the data", the reason in the checks report
        $request->update(['status' => 'paused', 'customer_action' => \App\Domain\Applications\StaffDecisionService::ACTION_DATA, 'checks_report' => ['العنوان ناقص رقم الشقة']]);

        $fix = FormLink::where('purpose', 'fix')->firstOrFail();
        $this->assertSame('العنوان ناقص رقم الشقة', $fix->staff_note);
        $this->assertSame('محمد علي حسن', $fix->answerValues()['name']);
        $this->assertStringContainsString($fix->url(), app(\App\Domain\Applications\StaffDecisionService::class)->message($request->refresh(), 'paused', 'العنوان ناقص رقم الشقة'));

        $this->answer($fix, 'home', ['governorate' => 'الجيزة', 'area' => 'فيصل', 'street' => 'شارع العشرين', 'building' => '12', 'apartment' => '7']);
        $this->postJson('/f/'.$fix->token.'/submit')->assertOk();

        $request->refresh();
        $this->assertSame(1, InstallmentRequest::count());
        $this->assertSame('pending', $request->status);
        $this->assertSame('7', $request->applicant_apartment);
        $log=\Spatie\Activitylog\Models\Activity::where('subject_id',$request->id)->where('description','العميل عدّل بيانات الطلب من الفورم')->firstOrFail();
        $this->assertSame('7',$log->properties['attributes']['applicant_apartment']);
        $this->assertSame('App\\Models\\Customer',$log->causer_type);
        $guide=\App\Models\GuideEvent::where('event','customer_form_updated')->where('subject_id',$request->id)->firstOrFail();
        $this->assertSame('العميل',$guide->actor_name);
        $this->assertSame('submitted', Application::find($request->application_id)->status);
    }

    public function test_the_bot_has_no_chat_intake_tools_in_form_mode_and_gets_them_back_in_bot_mode(): void
    {
        $names = array_column(app(ToolRegistry::class)->declarations(), 'name');
        $this->assertContains('send_application_link', $names);
        $this->assertNotContains('start_application', $names);
        $this->assertNotContains('record_customer_data', $names);
        $this->assertNotContains('submit_application', $names);

        config(['agent.applications.mode' => 'bot']);
        $names = array_column(app(ToolRegistry::class)->declarations(), 'name');
        $this->assertContains('start_application', $names);
        $this->assertNotContains('send_application_link', $names);
    }

    public function test_the_link_tool_marks_the_link_for_this_turn_and_refuses_a_second_application(): void
    {
        $this->form();
        $ctx = new ToolContext($this->conversation->customer_id, $this->conversation->id, null, 777, 1, new TurnResultBuilder());

        $result = app(SendApplicationLinkTool::class)->execute(['motorcycle_id' => $this->machine->id], $ctx)->toArray();
        $this->assertTrue($result['ok']);
        $link = FormLink::firstOrFail();
        $this->assertSame(777, $link->prefill['_sent_turn_id']);

        InstallmentRequest::create(['whatsapp_conversation_id' => $this->conversation->id, 'status' => 'pending', 'applicant_name' => 'x',
            'applicant_phone' => '01011111111', 'machine_id' => $this->machine->id, 'deposit' => 0, 'installment_type' => 'S', 'months' => 12, 'machine_installment_price' => 55000]);
        $again = app(SendApplicationLinkTool::class)->execute([], $ctx)->toArray();
        $this->assertTrue($again['data']['has_open_request']);
    }

    public function test_a_form_cannot_be_published_without_the_fields_a_request_needs(): void
    {
        $form = DynamicForm::create(['name' => 'x', 'schema' => ['sections' => [['title' => 'a', 'items' => [
            ['type' => 'text', 'data' => ['key' => 'a', 'label' => 'A', 'binding' => 'applicant.name']],
            ['type' => 'text', 'data' => ['key' => 'b', 'label' => 'B', 'binding' => 'applicant.name']],
        ]]]]]);

        $problems = app(FormPublisher::class)->publish($form, null);

        $this->assertNotEmpty($problems);
        $this->assertTrue(collect($problems)->contains(fn ($p) => str_contains($p, 'مستخدم في حقلين')));
        $this->assertTrue(collect($problems)->contains(fn ($p) => str_contains($p, 'المكنة')));
        $this->assertNull($form->refresh()->published_version_id);
    }

    private function actAsAdmin(): void
    {
        $this->staff->forceFill(['is_admin' => true, 'is_hitler' => true])->save();
        $this->actingAs($this->staff, 'filament');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    public function test_the_starter_form_is_built_from_the_current_rules_and_publishes(): void
    {
        CustomerType::create(['key' => 'pension', 'label' => 'معاش', 'legacy_work_status' => 'pension']);
        CustomerType::create(['key' => 'business_owner', 'label' => 'صاحب نشاط', 'legacy_work_status' => 'self_employed']);

        $this->artisan('forms:seed-default', ['--publish' => true])->assertSuccessful();

        $form = DynamicForm::where('name', 'طلب تقسيط موتوسيكل')->firstOrFail();
        $this->assertNotNull($form->published_version_id);
        $schema = $form->publishedVersion->formSchema();
        // a woman on delivery work is stopped, a man is not
        $this->assertNotEmpty($schema->blockingMessages(['national_id' => '29001011234567', 'work' => 'delivery']));
        $this->assertSame([], $schema->blockingMessages(['national_id' => '29001011234577', 'work' => 'delivery']));
        $this->assertArrayHasKey('section:guarantor', $schema->visible(['work' => 'pension']));
        $this->assertArrayNotHasKey('section:guarantor', $schema->visible(['work' => 'employee']));
    }

    public function test_the_dashboard_pages_render(): void
    {
        $this->actAsAdmin();
        $form = $this->form();
        $link = app(FormLinkService::class)->linkFor($this->conversation);
        $this->fillValid($link);
        $this->answer($link, 'job_years', '5 سنين');
        FormLinkFile::where('form_link_id', $link->id)->update(['check_status' => 'warning', 'message' => 'الصورة مش واضحة']);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();
        $request = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();

        \Livewire\Livewire::test(\App\Filament\Resources\DynamicFormResource\Pages\BuildDynamicForm::class, ['record' => $form->id])
            ->assertOk()->assertSee('من السيستم')->assertSee('خطوة جديدة');
        \Livewire\Livewire::test(\App\Filament\Resources\DynamicFormResource\Pages\ListDynamicForms::class)
            ->assertOk()->assertSee('طلب تقسيط');
        \Livewire\Livewire::test(\App\Filament\Resources\FormLinkResource\Pages\ListFormLinks::class)
            ->assertOk()->assertSee('01012345678')->assertSee('بعت الطلب')
            ->set('stage', 'submitted')->assertSee('محمد علي حسن')
            ->set('stage', 'waiting')->assertDontSee('محمد علي حسن');
        \Livewire\Livewire::test(\App\Filament\Resources\FormLinkResource\Pages\ViewFormLink::class, ['record' => $link->id])
            ->assertOk()->assertSee('محمد علي حسن')->assertSee('الصورة مش واضحة');
        \Livewire\Livewire::test(\App\Filament\Resources\DeliveryResource\Pages\EditDelivery::class, ['record' => $request->id])
            ->assertOk()->assertSee('بيانات إضافية من فورم التقديم')->assertSee('5 سنين');
    }

    public function test_publish_from_the_builder_freezes_a_version_and_preview_never_sends(): void
    {
        $this->actAsAdmin();
        $form = $this->form();

        $page = \Livewire\Livewire::test(\App\Filament\Resources\DynamicFormResource\Pages\BuildDynamicForm::class, ['record' => $form->id]);
        $schema = $form->schema;
        $schema['sections'][0]['items'][0]['data']['label'] = 'الاسم بالكامل';
        $page->call('publishForm', ['name' => 'طلب تقسيط', 'is_bot_default' => true, 'is_active' => true], $schema);
        $this->assertSame(2, $form->refresh()->publishedVersion->version);
        $this->assertSame('الاسم بالكامل', $form->publishedVersion->formSchema()->field('name')['label']);

        // a broken form is not published, and says why
        $broken = $schema;
        $broken['sections'][1]['items'] = [];
        $page->call('publishForm', ['name' => 'طلب تقسيط'], $broken);
        $this->assertSame(2, $form->refresh()->publishedVersion->version);

        $preview = app(FormPublisher::class)->preview($form, $this->staff->id);
        $this->get('/f/'.$preview->token)->assertOk();
        $this->postJson('/f/'.$preview->token.'/submit')->assertStatus(422);
        $this->assertSame(0, InstallmentRequest::count());
    }

    public function test_several_guarantors_fill_the_guarantors_list_and_the_first_fills_the_single_columns(): void
    {
        $this->form([
            ['type' => 'group', 'data' => ['key' => 'gs', 'label' => 'ضامن', 'required' => true, 'binding' => 'guarantor.list', 'min_items' => 1, 'max_items' => 3, 'fields' => [
                ['key' => 'n', 'label' => 'الاسم', 'type' => 'text', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'p', 'label' => 'الموبايل', 'type' => 'phone', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'id', 'label' => 'الرقم القومي', 'type' => 'national_id', 'required' => false, 'maps_to' => 'national_id'],
            ]]],
        ]);
        $link = app(FormLinkService::class)->linkFor($this->conversation);
        $this->fillValid($link);

        $this->answer($link, 'gs', [['n' => 'سامي فؤاد', 'p' => '0101']])->assertJsonPath('error', 'الموبايل (1): اكتب رقم موبايل مصري صحيح (11 رقم يبدأ بـ 01)');
        $this->answer($link, 'gs', [['n' => 'سامي فؤاد', 'p' => '01011112222', 'id' => '28501011234577'], ['n' => 'هاني']]);
        $this->postJson('/f/'.$link->token.'/submit')->assertStatus(422)->assertJsonPath('errors.gs', 'كمّل الموبايل (2)');

        $this->answer($link, 'gs', [['n' => 'سامي فؤاد', 'p' => '01011112222', 'id' => '28501011234577'], ['n' => 'هاني عادل', 'p' => '01233334444']]);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();

        $request = InstallmentRequest::where('form_link_id', $link->id)->firstOrFail();
        $this->assertSame('سامي فؤاد', $request->guarantor_name);
        $this->assertSame('28501011234577', $request->guarantor_national_id);
        $this->assertCount(2, $request->guarantors);
        $this->assertSame('01233334444', $request->guarantors[1]['phone']);
    }

    public function test_a_url_or_link_placeholder_the_model_writes_is_dropped_when_the_real_link_goes_out(): void
    {
        $runner = app(\App\Agent\Runtime\AgentRunner::class);
        $strip = new \ReflectionMethod($runner, 'withoutWrittenLink');
        $outcomes = [['name' => 'send_application_link', 'ok' => true, 'data' => ['link_sent_after_your_reply' => true]]];

        $args = $strip->invoke($runner, ['messages' => ["ده رابط التقديم، املا بياناتك عليه:\n\nhttps://motoget.com/apply/123456789", '[رابط التقديم]']], $outcomes);
        $this->assertSame(['ده رابط التقديم، املا بياناتك عليه'], $args['messages']);

        // no link tool this turn: nothing touched
        $same = ['messages' => ['شوف https://motogate-eg.com']];
        $this->assertSame($same, $strip->invoke($runner, $same, []));
    }

    private function workProfile(array $profile): void
    {
        $state = $this->conversation->state ?? [];
        $state['work_profile'] = $profile + ['work_stated' => true, 'applicant' => 'customer'];
        $this->conversation->update(['state' => $state]);
    }

    private function toolContext(int $turn = 900): ToolContext
    {
        return new ToolContext($this->conversation->customer_id, $this->conversation->id, null, $turn, 1, new TurnResultBuilder());
    }

    public function test_the_link_comes_filled_with_everything_the_chat_settled(): void
    {
        $this->form();
        $this->workProfile(['customer_type' => 'employee', 'work_type' => 'none', 'stated_monthly_income' => 8000]);

        $result = app(SendApplicationLinkTool::class)->execute(['customer_name' => 'محمد علي حسن', 'motorcycle_id' => $this->machine->id, 'months' => 12], $this->toolContext())->toArray();

        $answers = FormLink::firstOrFail()->answerValues();
        $this->assertSame('محمد علي حسن', $answers['name']);
        $this->assertSame('01012345678', $answers['phone']);
        $this->assertSame('employee', $answers['work']);
        $this->assertSame('8000', $answers['salary']);
        $this->assertSame($this->machine->id, $answers['machine']);
        $this->assertSame($this->plan->id, $answers['plan']);
        $this->assertContains('الموبايل', $result['data']['already_filled_for_him']);
    }

    public function test_applying_for_someone_else_leaves_his_own_name_and_number_out(): void
    {
        $this->form();
        $this->workProfile(['customer_type' => 'employee', 'applicant' => 'someone_else']);

        app(SendApplicationLinkTool::class)->execute(['customer_name' => 'محمد علي حسن'], $this->toolContext());

        $answers = FormLink::firstOrFail()->answerValues();
        $this->assertArrayNotHasKey('name', $answers);
        $this->assertArrayNotHasKey('phone', $answers);
        $this->assertSame('employee', $answers['work']);
    }

    public function test_two_job_options_of_one_type_are_told_apart_by_the_kind_of_work(): void
    {
        $form = DynamicForm::create(['name' => 'x', 'schema' => ['sections' => [['key' => 's', 'title' => 'شغلك', 'items' => [
            ['type' => 'radio', 'data' => ['key' => 'work', 'label' => 'شغلك', 'required' => true, 'binding' => 'work.status', 'options' => [
                ['value' => 'delivery', 'label' => 'دليفري', 'customer_type_id' => $this->freelancer->id, 'work_types' => ['delivery_app']],
                ['value' => 'free', 'label' => 'شغل حر', 'customer_type_id' => $this->freelancer->id, 'work_types' => ['craftsman', 'other']],
            ]]],
            ['type' => 'machine', 'data' => ['key' => 'machine', 'label' => 'المكنة', 'binding' => 'request.machine']],
            ['type' => 'plan', 'data' => ['key' => 'plan', 'label' => 'النظام', 'binding' => 'request.plan']],
        ]]]]]);
        $this->assertSame([], app(FormPublisher::class)->publish($form, null));
        $this->workProfile(['customer_type' => 'self_employed', 'work_type' => 'delivery_app']);

        app(SendApplicationLinkTool::class)->execute([], $this->toolContext());

        $this->assertSame('delivery', FormLink::firstOrFail()->answerValues()['work']);
    }

    public function test_above_the_cap_for_his_job_the_difference_is_shown_as_cash(): void
    {
        $link = $this->link();
        \App\Models\EligibilityRule::create(['customer_type_id' => $this->freelancer->id, 'rule_type' => 'financing_cap', 'params' => ['max_amount' => 40000], 'is_active' => true]);

        $this->answer($link, 'work', 'employee');
        $this->getJson('/f/'.$link->token.'/plans?machine='.$this->machine->id)->assertJsonPath('capped', false)->assertJsonPath('job_known', true);

        $this->answer($link, 'work', 'free');
        $plan = $this->getJson('/f/'.$link->token.'/plans?machine='.$this->machine->id)->assertJsonPath('capped', true)->json('plans.0');
        $this->assertTrue($plan['capped']);
        $this->assertGreaterThanOrEqual(15000, $plan['down_payment']);
    }

    public function test_the_id_card_fills_the_name_and_number_he_has_not_typed(): void
    {
        $link = $this->link();
        $this->answer($link, 'nid', '');
        $file = $this->uploadIdFront($link, 'checking');

        $pipeline = \Mockery::mock(\App\Domain\Documents\DocumentPipeline::class);
        $pipeline->shouldReceive('readForForm')->andReturn(['type' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['full_name' => 'محمود سعيد عبد الله', 'national_id' => '29001011234577']]);
        $this->app->instance(\App\Domain\Documents\DocumentPipeline::class, $pipeline);

        app(\App\Domain\Forms\FormDocumentChecker::class)->check($file->refresh());

        $answers = $link->refresh()->answerValues();
        $this->assertSame('محمود سعيد عبد الله', $answers['name']);
        $this->assertSame('29001011234577', $answers['nid']);
        $this->assertSame('ok', $file->refresh()->check_status);
        $this->getJson('/f/'.$link->token.'/files')->assertJsonPath('answers.name', 'محمود سعيد عبد الله');
    }

    public function test_the_follow_up_names_the_photo_that_needs_fixing(): void
    {
        $link = $this->link();
        $this->answer($link, 'name', 'محمد علي حسن');
        $this->uploadIdFront($link, 'warning');

        $text = app(FormLinkFollowUpService::class)->message($link->refresh());

        $this->assertStringContainsString('وش البطاقة', $text);
        $this->assertStringContainsString('الصورة مش واضحة', $text);
        $this->assertStringContainsString($link->url(), $text);
    }

    public function test_the_device_suffix_and_a_placeholder_name_never_reach_the_form(): void
    {
        $this->form();
        $this->conversation->update(['phone' => '213296665854115', 'real_phone' => '201200268302:0']);
        config(['agent.form_links.base_url' => 'http://192.168.1.5:8002']);

        app(SendApplicationLinkTool::class)->execute(['customer_name' => 'العميل'], $this->toolContext());

        $link = FormLink::firstOrFail();
        $this->assertSame('01200268302', $link->answerValues()['phone']);
        $this->assertArrayNotHasKey('name', $link->answerValues());
        $this->assertArrayNotHasKey('customer_name', (array) $link->prefill);
        $this->assertStringStartsWith('http://192.168.1.5:8002/f/', $link->url());
    }

    public function test_multiple_images_field_counts_files_and_wants_its_minimum(): void
    {
        $schema = new \App\Domain\Forms\FormSchema(['sections' => [[
            'key' => 's1', 'title' => 'الدخل', 'items' => [[
                'type' => 'images',
                'data' => ['key' => 'shots', 'label' => 'سكرينات التطبيق', 'required' => true, 'min_files' => 2, 'max_files' => 6],
            ]],
        ]]]);

        $this->assertSame('ارفع سكرينات التطبيق', $schema->submitErrors([], [])['fields']['shots']);
        $this->assertStringContainsString('2 صور على الأقل', $schema->submitErrors([], ['shots' => 1])['fields']['shots']);
        $this->assertSame([], $schema->submitErrors([], ['shots' => 2])['fields']);
        $this->assertSame(['done' => 1, 'total' => 1, 'missing' => []], $schema->progress([], ['shots' => 3]));
    }

    public function test_after_staff_cancel_the_bot_never_sends_the_dead_link_and_he_gets_a_fresh_one_with_his_answers(): void
    {
        $link = $this->link();
        $this->answer($link, 'name', 'محمد علي حسن');
        $link->update(['status' => 'cancelled']);

        $runner = app(\App\Agent\Runtime\AgentRunner::class);
        $strip = new \ReflectionMethod($runner, 'withoutWrittenLink');
        $attach = new \ReflectionMethod($runner, 'withApplicationLink');

        // the model copied the old link from the history
        $args = $strip->invoke($runner, ['messages' => ['ده رابط التقديم: '.$link->url()]], [], $this->conversation->id);
        $this->assertSame(['ده رابط التقديم'], $args['messages']);

        $turn = (object) ['id' => 4242, 'whatsapp_conversation_id' => $this->conversation->id];
        $result = $attach->invoke($runner, $turn, ['messages' => $args['messages']]);

        $fresh = FormLink::where('id', '!=', $link->id)->firstOrFail();
        $this->assertSame($fresh->url(), end($result['messages']));
        $this->assertNotSame($link->url(), $fresh->url());
        $this->assertSame('محمد علي حسن', $fresh->answerValues()['name']);
        $this->assertSame($link->id, $fresh->renewed_from_id);
    }

    public function test_no_fresh_link_while_a_request_of_his_waits_for_a_decision(): void
    {
        $link = $this->link();
        $this->fillValid($link);
        $this->postJson('/f/'.$link->token.'/submit')->assertOk();

        $runner = app(\App\Agent\Runtime\AgentRunner::class);
        $attach = new \ReflectionMethod($runner, 'withApplicationLink');
        $result = $attach->invoke($runner, (object) ['id' => 4243, 'whatsapp_conversation_id' => $this->conversation->id], ['messages' => ['طلبك وصل، والرابط مش محتاج تفتحه تاني']]);

        $this->assertCount(1, $result['messages']);
        $this->assertSame(1, FormLink::count());
    }

    public function test_admin_switches_a_cancelled_link_back_on(): void
    {
        $this->actAsAdmin();
        $link = $this->link();
        $this->answer($link, 'name', 'محمد علي حسن');
        $link->update(['status' => 'cancelled', 'followups_sent' => 2]);

        \Livewire\Livewire::test(\App\Filament\Resources\FormLinkResource\Pages\ViewFormLink::class, ['record' => $link->id])
            ->callAction('reactivate');

        $link->refresh();
        $this->assertSame('in_progress', $link->status);
        $this->assertTrue($link->isOpen());
        $this->assertSame(0, $link->followups_sent);
        $this->get('/f/'.$link->token)->assertOk()->assertDontSee('الرابط ده مبقاش شغال');
    }

    public function test_sending_from_a_number_that_is_not_connected_says_so_in_plain_words(): void
    {
        $link = $this->link();
        $this->bot->update(['is_active' => false]);

        $problem = \App\Filament\Resources\FormLinkResource::sendToCustomer($link->refresh(), 'ده الرابط');

        $this->assertStringContainsString('مش متوصل', $problem);
        Http::assertNothingSent();
    }

    public function test_owner_form_requires_the_correct_documents_for_each_employment_case(): void
    {
        $schema=new \App\Domain\Forms\FormSchema(json_decode(file_get_contents(resource_path('forms/motogate-form1-v2.json')),true));
        $this->assertSame([],$schema->publishProblems());
        foreach(['government','employee']as$work){$v=$schema->visible(['work'=>$work]);$this->assertArrayHasKey('salary_slip',$v);$this->assertTrue($schema->field('salary_slip')['required']);$this->assertArrayHasKey('place_name',$v);}
        $v=$schema->visible(['work'=>'private_uninsured']);$this->assertArrayNotHasKey('salary_slip',$v);$this->assertSame(2,$schema->customerTypeId(['work'=>'private_uninsured']));
        $v=$schema->visible(['work'=>'smart_transport']);$this->assertArrayHasKey('license',$v);$this->assertArrayHasKey('car_license',$v);$this->assertArrayNotHasKey('work_address',$v);$this->assertArrayNotHasKey('earnings',$v);$this->assertArrayNotHasKey('salary_slip',$v);
    }

    public function test_landmark_is_accepted_and_the_missing_address_part_is_named_correctly(): void
    {
        $schema=new \App\Domain\Forms\FormSchema(json_decode(file_get_contents(resource_path('forms/motogate-form1-v2.json')),true));
        $home=['governorate'=>'القاهرة','area'=>'عين شمس','street'=>'الزهراء','landmark'=>'جنب مدرسة الخنساء'];
        $errors=$schema->submitErrors(['work'=>'private_uninsured','home'=>$home],[])['fields'];$this->assertArrayNotHasKey('home',$errors);
        unset($home['street']);$errors=$schema->submitErrors(['work'=>'private_uninsured','home'=>$home],[])['fields'];$this->assertStringContainsString('الشارع',$errors['home']);$this->assertStringNotContainsString('علامة مميزة',$errors['home']);
    }

    public function test_server_requires_exact_confirmation_of_the_current_financing_cap_amount(): void
    {
        $link=$this->link();$this->fillValid($link);$this->answer($link,'work','free');
        \App\Models\EligibilityRule::create(['customer_type_id'=>$this->freelancer->id,'rule_type'=>'financing_cap','params'=>['max_amount'=>40000],'is_active'=>true]);
        $v=$link->version;$schema=$v->schema;$schema['settings']['confirm_financing_cap']=true;$v->update(['schema'=>$schema]);
        $plan=$this->getJson('/f/'.$link->token.'/plans?machine='.$this->machine->id)->json('plans.0');$this->answer($link,'plan',$plan['id']);
        $this->postJson('/f/'.$link->token.'/submit')->assertStatus(422)->assertJsonPath('code','CAP_CONFIRMATION_REQUIRED');
        $consent=['machine_id'=>$this->machine->id,'plan_id'=>$plan['id'],'amount'=>(int)$plan['down_payment']];
        $wrong=$consent;$wrong['amount']=1;$this->postJson('/f/'.$link->token.'/submit',['cap_confirmation'=>$wrong])->assertStatus(422)->assertJsonPath('code','CAP_CONFIRMATION_REQUIRED');
        $this->postJson('/f/'.$link->token.'/submit',['cap_confirmation'=>$consent])->assertOk()->assertJsonPath('ok',true);
        $this->assertSame($consent['amount'],$link->fresh()->answerValues()['__financing_cap_consent']['amount']);
    }

    public function test_identity_guard_applies_to_model_creation_by_a_different_staff_and_not_name_only(): void
    {
        $link=$this->link();$this->fillValid($link);$this->postJson('/f/'.$link->token.'/submit')->assertOk();$first=InstallmentRequest::firstOrFail();
        $first->forceFill(['applicant_phone'=>'+20 10-1234-5678'])->saveQuietly();
        $copy=$first->replicate(['application_id','form_link_id']);$copy->applicant_phone='201012345678';
        try{$copy->save();$this->fail('Duplicate identity escaped model guard');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('applicant_national_id',$e->errors());}
        $copy=$first->replicate(['application_id','form_link_id']);$copy->applicant_national_id='29101011234565';$copy->applicant_phone='01012345678';
        try{$copy->save();$this->fail('Formatted phone escaped duplicate guard');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('applicant_national_id',$e->errors());}
        $copy=$first->replicate(['application_id','form_link_id']);$copy->applicant_phone='01077777777';$copy->applicant_national_id='29101011234565';$copy->save();
        $this->assertSame($first->applicant_name,$copy->applicant_name);$this->assertSame(2,InstallmentRequest::count());
    }

    public function test_explicit_employee_request_transfers_even_when_model_quote_is_concatenated_wrongly(): void
    {
        $settings=json_decode(file_get_contents(resource_path('agent/policies/intake-quality-settings.json')),true);
        foreach($settings['settings']as$k=>$v)config(['agent.'.$k=>$v]);config(['agent.handoff.customer_only'=>true,'agent.safety.language_enabled'=>true,'agent.runtime.max_model_calls'=>5,'agent.runtime.max_tool_calls'=>10,'agent.runtime.wall_clock_seconds'=>30,'agent.fallback.message'=>'safe test fallback']);
        $id=\Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$this->bot->id,'whatsapp_conversation_id'=>$this->conversation->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
        foreach(['أنا عايز موظف يكلمني','عشن احجز']as$text)WhatsappMessage::create(['whatsapp_bot_id'=>$this->bot->id,'whatsapp_conversation_id'=>$this->conversation->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>$text]);
        $fake=new \App\Agent\Providers\FakeAiProvider;
        $fake->queue(new \App\Agent\Providers\AiResponse([], [['id'=>'handoff','name'=>'handoff_to_human','args'=>['reason'=>'call_request','note'=>'customer wants staff','request_quote'=>'اي داأنا عايز موظف يكلمنيعشن احجز']]],'STOP',[],'test',null,1));
        app()->instance(\App\Agent\Providers\AiProvider::class,$fake);
        $runner=app(\App\Agent\Runtime\AgentRunner::class);$r=$runner->run(\Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->find($id));
        $this->assertSame('awaiting_agent',$this->conversation->fresh()->status);$this->assertSame([config('agent.handoff.customer_message')],$r['messages']);
        $again=$runner->run(\Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->find($id));$this->assertTrue($again['no_reply']);
    }
}
