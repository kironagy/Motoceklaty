<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Schema,Cache};
use App\Agent\Runtime\{CatalogMentions,MotorcycleSelection};
class BotContinuityRegressionTest extends TestCase
{
 protected function setUp():void {parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);DB::purge('sqlite');
 Schema::create('brands',function($t){$t->id();$t->string('name');});
 Schema::create('machines',function($t){$t->id();$t->string('name');$t->unsignedBigInteger('brand_id')->nullable();$t->text('aliases')->nullable();$t->boolean('is_active')->default(true);});
 Schema::create('whatsapp_messages',function($t){$t->id();$t->unsignedBigInteger('whatsapp_conversation_id');$t->string('direction');$t->text('text')->nullable();$t->text('transcript')->nullable();});
 DB::table('machines')->insert([['id'=>1,'name'=>'Scooter X Road i','aliases'=>'[]'],['id'=>2,'name'=>'KTX 250','aliases'=>'[]'],['id'=>3,'name'=>'دايو 2','aliases'=>'[]'],['id'=>4,'name'=>'دايو ٤','aliases'=>'[]']]);Cache::flush();}
 public function test_customer_model_shorthand_survives_work_answers_and_duration_is_not_asked_again():void{
 $catalog=app(CatalogMentions::class);$this->assertSame([1],$catalog->fullNames('X ROAD'));$this->assertSame([2],$catalog->fullNames('ktx'));$this->assertSame([3],$catalog->fullNames('دايو اتنين'));
 DB::table('whatsapp_messages')->insert([['whatsapp_conversation_id'=>1,'direction'=>'incoming','text'=>'X ROAD'],['whatsapp_conversation_id'=>1,'direction'=>'incoming','text'=>'سنتين'],['whatsapp_conversation_id'=>1,'direction'=>'incoming','text'=>'شغل حر'],['whatsapp_conversation_id'=>1,'direction'=>'incoming','text'=>'بالبطاقة بس']]);
 $s=app(MotorcycleSelection::class);$this->assertSame(1,$s->lastNamed(1));$this->assertSame(24,$s->lastDuration(1));
 DB::table('whatsapp_messages')->insert(['whatsapp_conversation_id'=>1,'direction'=>'incoming','text'=>'دايون4استيراد']);$this->assertNull($s->lastNamed(1));
 }
 public function test_voice_durations_are_kept_and_age_is_not_a_duration_and_private_data_is_redirected():void{
 DB::table('whatsapp_messages')->insert(['whatsapp_conversation_id'=>2,'direction'=>'incoming','text'=>'','transcript'=>'على السنة بكام وعلى السنتين بكام؟']);
 DB::table('whatsapp_messages')->insert(['whatsapp_conversation_id'=>2,'direction'=>'incoming','text'=>'عندي 22 سنة','transcript'=>null]);
 $this->assertSame([12,24],app(MotorcycleSelection::class)->lastDurations(2));
 $policy=app(\App\Agent\Runtime\FormLinkIntakePolicy::class);
 $this->assertTrue($policy->asksForPrivateData('ابعتلي صورة البطاقة واسمك ورقمك'));
 $this->assertFalse($policy->asksForPrivateData('املأ بياناتك وارفع المستندات على الرابط'));
 $this->assertFalse($policy->asksForPrivateData('حضرتك موظف ولا شغل حر؟'));
 }
}
