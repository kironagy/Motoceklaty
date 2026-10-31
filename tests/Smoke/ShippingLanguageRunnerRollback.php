<?php
if(($argv[1]??'')!=='--rollback-check')exit("Use --rollback-check\n");
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();
$make=function($question)use($bot){$jid='sim-shipping-language-'.bin2hex(random_bytes(5));$customer=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$customer->id,'phone'=>$jid,'status'=>'open']);$id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>$question]);return [$c,$id];};
config(['agent.safety.reply_guard_mode'=>'advisory','agent.safety.language_enabled'=>true]);
[$c,$id]=$make('عايز أعرف شروط التقديم');$fake=new FakeAiProvider;
foreach([1,2]as$i)$fake->queue(new AiResponse([], [['id'=>'bad-'.$i,'name'=>'send_reply','args'=>['messages'=>['المبغوتة تنفع، سنها كام؟']]]],'STOP',[],'test',null,1));
$app->instance(AiProvider::class,$fake);$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id));
if($r['messages']!==[config('agent.safety.language_safe_reply')])throw new RuntimeException('Unsafe wording escaped mandatory guard');
echo "PASS real runner blocks repeated nonsense even in advisory mode and sends safe dashboard reply\n";
if($c->fresh()->status!=='open')throw new RuntimeException('Language error transferred customer');echo "PASS language repair does not hand off\n";
[$cash,$cid]=$make('عايزها كاش، بتشحنوا للمنيا؟');$fake=new FakeAiProvider;
$fake->queue(new AiResponse([], [['id'=>'shipping-call','name'=>'handoff_to_human','args'=>['reason'=>'cash_shipping_request','note'=>'cash shipping','request_quote'=>'بتشحنوا للمنيا؟']]],'STOP',[],'test',null,1));
$fake->queue(new AiResponse(['{"approved":true,"reason":"cash shipping request"}'],[],'STOP',[],'test',null,1));$app->instance(AiProvider::class,$fake);
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($cid));
if($cash->fresh()->status!=='awaiting_agent'||$r['messages']!==[config('agent.cash.shipping_handoff_message')])throw new RuntimeException('Shipping runner did not transfer and acknowledge');echo "PASS real runner queues cash shipping and returns approved notice\n";
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($cid));if(!($r['no_reply']??false)||$r['messages']!==[])throw new RuntimeException('Bot answered handed off shipping enquiry');echo "PASS real runner stays silent after shipping transfer\n";
}finally{DB::rollBack();echo "Synthetic records rolled back; no WhatsApp delivery invoked\n";}
