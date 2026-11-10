<?php

namespace Tests\Feature;

use App\Agent\Runtime\{CatalogMentions, ConversationIntent, MotorcycleSelection, TurnResultBuilder};
use App\Agent\Tools\{ToolContext, ToolRegistry};
use Illuminate\Support\Facades\{DB, Schema, Cache};
use Tests\TestCase;

class BotCustomerIntentRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array']);
        DB::purge('sqlite');
        Schema::create('brands', fn ($t) => [$t->id(), $t->string('name')]);
        Schema::create('machines', function ($t) { $t->id(); $t->string('name'); $t->unsignedBigInteger('brand_id')->nullable(); $t->text('aliases')->nullable(); $t->boolean('is_active')->default(true); $t->string('type')->default('normal'); $t->decimal('cash_price')->default(0); $t->decimal('new_price')->nullable(); });
        Schema::create('whatsapp_messages', function ($t) { $t->id(); $t->unsignedBigInteger('whatsapp_conversation_id'); $t->string('direction'); $t->string('sender_type')->nullable(); $t->text('text')->nullable(); $t->text('transcript')->nullable(); });
        Schema::create('whatsapp_conversations', function ($t) { $t->id(); $t->text('state')->nullable(); });
        DB::table('whatsapp_conversations')->insert(['id'=>1,'state'=>'{}']);
        DB::table('machines')->insert([
            ['id'=>1,'name'=>'Z250','aliases'=>'[]','cash_price'=>70000], ['id'=>2,'name'=>'دايو 2','aliases'=>'[]','cash_price'=>35500],
            ['id'=>3,'name'=>'دايو ٤','aliases'=>'[]','cash_price'=>39000], ['id'=>4,'name'=>'keet 2000w','aliases'=>'[]','cash_price'=>46000],
            ['id'=>5,'name'=>'RK200 R','aliases'=>'[]','cash_price'=>55000], ['id'=>6,'name'=>'دايو ٤ اصلي','aliases'=>'[]','cash_price'=>47000],
            ['id'=>7,'name'=>'Keeway keet 150','aliases'=>'[]','cash_price'=>53000],
        ]);
        Cache::flush();
    }

    private function message(string $direction, string $text, int $cid = 1): void
    {
        DB::table('whatsapp_messages')->insert(['whatsapp_conversation_id'=>$cid,'direction'=>$direction,'text'=>$text]);
    }

    public function test_arabic_model_and_two_models_are_understood_without_guessing_ids(): void
    {
        $catalog = app(CatalogMentions::class);
        $this->assertSame([1], $catalog->fullNames('عايز صور الزد'));
        $this->assertEqualsCanonicalizing([2,3], $catalog->fullNames('دايو ٢ و٤'));
        $this->assertSame([5], $catalog->fullNames('rk200'));
        $this->assertSame([1], $catalog->fullNames('الـZ250'));
        $this->assertSame([4], $catalog->fullNames('وكيت 2000 وات'));
        $this->assertSame([7], $catalog->fullNames('كيوي كيت 150'));
        $this->assertSame([], $catalog->fullNames('كيت 99'));
        $this->message('incoming','انا مش عايز اقدم عايز صور الزد');
        $this->assertSame(1,app(MotorcycleSelection::class)->lastNamed(1));
    }

    public function test_price_reference_is_a_selection_only_among_discussed_models(): void
    {
        $this->message('outgoing', 'ديمورا ٣٥ ألف و كيت ٢٠٠٠ وات بـ٤٦ ألف');
        $this->message('incoming', 'اللي 46 الف دا');
        $this->message('outgoing', 'على كام سنة؟');
        $this->message('incoming', 'سنه');
        $this->assertSame(4, app(MotorcycleSelection::class)->lastNamed(1));
        $this->assertSame(12, app(MotorcycleSelection::class)->lastDuration(1));
        $this->message('incoming', 'اللي 70 الف', 2);
        $this->assertNull(app(MotorcycleSelection::class)->lastNamed(2));
    }

    public function test_plain_variant_answer_survives_followups(): void
    {
        $this->message('outgoing','دايو ٤ العادية بـ٣٩ ألف ودايو ٤ اصلي بـ٤٧ ألف، أنهي نسخة؟');
        $this->message('incoming','العادية');
        $this->message('incoming','شغل حر');
        $this->assertSame(3, app(MotorcycleSelection::class)->lastNamed(1));
    }

    public function test_bare_three_is_duration_after_question_but_not_an_age(): void
    {
        $this->message('incoming','RK200');
        $this->message('outgoing','تحب القسط على سنة ولا سنة ونص ولا سنتين ولا ٣ سنين؟');
        $this->message('incoming','3');
        $this->message('incoming','موظف');
        $this->assertSame(36, app(MotorcycleSelection::class)->lastDuration(1));
        $this->assertSame(5, app(MotorcycleSelection::class)->lastNamed(1));
        $this->message('outgoing','سنك كام؟',2); $this->message('incoming','3',2);
        $this->assertNull(app(MotorcycleSelection::class)->lastDuration(2));
    }

    public function test_refused_application_and_photo_followup_do_not_start_an_interview(): void
    {
        $intent = app(ConversationIntent::class);
        $this->assertTrue($intent->refusesApplication('انا مش عايز اقدم عايز صور الزد'));
        $this->assertFalse($intent->asksToApply('انا مش عايز اقدم عايز صور الزد','نبدأ التقديم؟'));
        $this->assertTrue($intent->asksForPhotos('الزد طيب','تقصد صور أنهي موديل؟'));
        $this->assertFalse($intent->asksForPhotos('مش عايز صور'));
        $this->assertTrue($intent->asksToApply('تمام','نبدأ التقديم؟'));
        $this->assertTrue($intent->asksToApply('ابعت رابط التقديم'));
        $this->assertFalse($intent->asksToApply('عايز صور الزد','نبدأ التقديم؟'));
    }

    public function test_early_financial_reply_keeps_already_queued_media_without_duplicates(): void
    {
        $builder = new TurnResultBuilder();
        $image = ['path'=>'machines/z.jpg','motorcycle_id'=>1];
        $builder->addMedia($image);
        $builder->addTrailingMessage('رابط حقيقي');
        $result = $builder->mergeInto(['messages'=>['القسط 3000'], 'media'=>[$image], 'no_reply'=>true]);
        $this->assertCount(1,$result['media']);
        $this->assertSame(['القسط 3000','رابط حقيقي'],$result['messages']);
        $this->assertArrayNotHasKey('no_reply',$result);
    }

    public function test_gemini_is_never_a_customer_reply_or_review_fallback(): void
    {
        $models = ['gemini-3.1-flash-lite','bormaa:gpt-5.6-luna','bormaa:gpt-5.6-sol'];
        foreach (['reply','simulator',null] as $purpose) {
            $request = new \App\Agent\Providers\AiRequest(purpose:$purpose,label:'main');
            $this->assertSame(array_slice($models,1),\App\Agent\Providers\RoutingAiProvider::allowedModels($request,$models,bormaaLive:true));
        }
        foreach (['image','document'] as $purpose) $this->assertSame($models,\App\Agent\Providers\RoutingAiProvider::allowedModels(new \App\Agent\Providers\AiRequest(purpose:$purpose),$models,bormaaLive:true));
        $voice = new \App\Agent\Providers\AiRequest(purpose:'voice',label:'voice',toolMode:'none');
        $this->assertSame($models,\App\Agent\Providers\RoutingAiProvider::allowedModels($voice,$models,bormaaLive:true));
    }
}
