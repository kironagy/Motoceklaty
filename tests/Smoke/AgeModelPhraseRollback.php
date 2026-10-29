<?php
if(($argv[1]??'')!=='--rollback-check')exit("Use --rollback-check\n");
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};use App\Agent\Runtime\{AgentRunner,ReplyPhrasePolicy};
$n=0;$ok=function($v,$label)use(&$n){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";$n++;};
$phrase='ما عنديش معلومة مؤكدة أقدر أقولها عن النقطة دي.';$policy=new ReplyPhrasePolicy;
$ok($policy->apply(['messages'=>[$phrase]])['args']['messages']===[],'forbidden template removed');
$ok($policy->apply(['messages'=>['سعر الكاش 39,000 جنيه. '.$phrase]])['args']['messages']===['سعر الكاش 39,000 جنيه.'],'valid answer retained when forbidden sentence is removed');
$ok(!$policy->apply(['messages'=>['الموتور 200 سي سي.']])['changed'],'ordinary specifications untouched');
$ok($policy->apply(['messages'=>['معنديش معلومات مؤكدة عنها.']])['changed'],'plural spelling also removed');
$machine=App\Models\Machine::findOrFail(3);$baseBefore=$machine->installment_price;$display=(new ReflectionMethod(App\Agent\Tools\GetInstallmentOfferTool::class,'cashPrice'))->invoke(app(App\Agent\Tools\GetInstallmentOfferTool::class),$machine);
$ok($display==39000,'Dayou 4 cash display uses active 39000 offer');$ok($machine->installment_price===$baseBefore,'installment calculation base remains unchanged');
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();
$make=function($question)use($bot){$jid='sim-age-model-'.bin2hex(random_bytes(5));$u=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$u->id,'phone'=>$jid,'status'=>'open']);$id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>$question]);return[$c,$id];};
[$c,$id]=$make('الديو 39000 على سنتين');
$trace=App\Models\AiTrace::create(['conversation_id'=>$c->id,'turn_id'=>$id,'status'=>'running']);
App\Models\AiTraceStep::create(['trace_id'=>$trace->id,'turn_id'=>$id,'seq'=>1,'kind'=>'tool_call','tool_name'=>'search_motorcycles','permission'=>'READ','args_hash'=>'test','args_redacted'=>[],'result_redacted'=>['ok'=>true,'data'=>['items'=>[['id'=>30]]]]]);
$ok(App\Domain\Conversations\MentionedMotorcycle::conflict($c->id,$id,App\Models\Machine::findOrFail(30))!==null,'matching price and broad search cannot replace explicit Dayou brand with Vigory');
$ok(App\Domain\Conversations\MentionedMotorcycle::conflict($c->id,$id,$machine)===null,'correct Dayou model remains allowed');
[$young,$ageTurn]=$make('بنتي سنها 20 سنة');$ctx=new App\Agent\Tools\ToolContext($young->customer_id,$young->id,null,$ageTurn,1,new App\Agent\Runtime\TurnResultBuilder());
$r=app(App\Agent\Tools\CheckEligibilityTool::class)->execute(['age'=>20,'about'=>'other_applicant'],$ctx)->toArray();
$ok(($r['data']['status']??'')==='not_eligible','stated age 20 is ineligible');
$ok(($r['data']['work_questions_required']??true)===false,'age rejection stops work questions');
$ok(($r['data']['next_step']??'')==='age_ineligible_use_eligible_alternative','eligible alternative is the next step');
foreach([false,true]as$repeat){[$q,$tid]=$make('الدايو 4 كم قسط');$fake=new FakeAiProvider;
$fake->queue(new AiResponse([], [['id'=>'first','name'=>'send_reply','args'=>['messages'=>[$phrase]]]],'STOP',[],'test',null,1));
$good='حضرتك تحب تقسط على سنة ولا سنتين؟';$fake->queue(new AiResponse([], [['id'=>'second','name'=>'send_reply','args'=>['messages'=>[$repeat?$phrase:$good]]]],'STOP',[],'test',null,1));$app->instance(AiProvider::class,$fake);
$r=app(AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($tid));
$ok(!$policy->apply($r)['changed'],'forbidden template never returned from real runner');
$ok(($r['messages'][0]??'')===($repeat?config('agent.fallback.message'):$good),$repeat?'repeated forbidden answer becomes technical failure, never unknown template':'model gets one targeted rephrase and answer is sent');
}
}finally{DB::rollBack();echo "Rolled back; no WhatsApp delivery\n";}
echo "$n passed\n";
