<?php
if(($argv[1]??'')!=='--rollback-check')exit("Use --rollback-check\n");
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};
config(['agent.safety.reply_guard_mode'=>'enforce']);
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();
$make=function($question)use($bot){$jid='sim-sales-'.bin2hex(random_bytes(5));$customer=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$customer->id,'phone'=>$jid,'status'=>'open']);$id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>$question]);return [$c,$id];};
[$c,$id]=$make('عايز بينيلي vlm كاش');
$ctx=new App\Agent\Tools\ToolContext($c->customer_id,$c->id,null,$id,1,new App\Agent\Runtime\TurnResultBuilder());
$search=app(App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['name_query'=>'VLM 200'],$ctx)->toArray();
$items=$search['data']['items'];if(count($items)!==1||$items[0]['brand']!=='بينيلي')throw new RuntimeException('Unrequested brand escaped search scope');
if(!empty($items[0]['cash_stock'])||!empty($items[0]['colors'])||!empty($items[0]['availability']))throw new RuntimeException('Public cash tool exposed stock');
echo "PASS real catalog returns only requested Benelli and hides stock\n";
[$c2,$id2]=$make('طيب والدايونج كاش؟');$ctx2=new App\Agent\Tools\ToolContext($c2->customer_id,$c2->id,null,$id2,1,new App\Agent\Runtime\TurnResultBuilder());$data=app(App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['name_query'=>'دايونج'],$ctx2)->toArray();if(($data['data']['items'][0]['id']??null)!==56)throw new RuntimeException('Dayong not found');echo "PASS Dayong lookup returns its catalog price\n";
$fake=new FakeAiProvider;$app->instance(AiProvider::class,$fake);
foreach([['name'=>'search_motorcycles','args'=>['name_query'=>'VLM 200']],['name'=>'send_reply','args'=>['messages'=>['بينيلي VLM 200 سعرها كاش 73,000 جنيه.']]]]as$i=>$call)$fake->queue(new AiResponse([], [['id'=>'sales-'.$i]+$call],'STOP',[],'test',null,1));
$fake->queue(new AiResponse(['{"approved":true,"reason":"verified catalog price"}'],[],'STOP',[],'test',null,1));
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id));if(($r['messages'][0]??'')!=='بينيلي VLM 200 سعرها كاش 73,000 جنيه.')throw new RuntimeException('Price query failed: '.json_encode($r));if($c->fresh()->status!=='open')throw new RuntimeException('Price inquiry transferred');echo "PASS real runner sends sourced cash price without transfer\n";
$fakeDay=new FakeAiProvider;
$fakeDay->queue(new AiResponse([], [['id'=>'dayong-search','name'=>'search_motorcycles','args'=>['name_query'=>'دايونج']]],'STOP',[],'test',null,1));
for($attempt=0;$attempt<3;$attempt++){
 $fakeDay->queue(new AiResponse([], [['id'=>'dayong-reply-'.$attempt,'name'=>'send_reply','args'=>['messages'=>['الدايونج سعرها كاش 45,000 جنيه.']]]],'STOP',[],'test',null,1));
 $fakeDay->queue(new AiResponse([$attempt<2?'{"approved":false,"reason":"synthetic repair feedback"}':'{"approved":true,"reason":"verified catalog cash price"}'],[],'STOP',[],'test',null,1));
}
$app->instance(AiProvider::class,$fakeDay);
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id2));
if(($r['messages'][0]??'')!=='الدايونج سعرها كاش 45,000 جنيه.')throw new RuntimeException('Dayong repair ended in fallback: '.json_encode($r));
echo "PASS real runner sends Dayong price after independent evidence repairs\n";
[$social,$sid]=$make('تمام');$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($sid));if($r['messages']!==['تمام.'])throw new RuntimeException('Acknowledgement fallback');echo "PASS real runner handles thanks with no model call\n";
[$buyer,$bid]=$make('عايز اشتريها كاش دلوقتي');$incoming=App\Models\WhatsappMessage::where('turn_id',$bid)->first();$incoming->delete();
App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$buyer->id,'whatsapp_bot_id'=>$bot->id,'direction'=>'outgoing','sender_type'=>'bot','type'=>'text','text'=>'بينيلي VLM 200 سعرها كاش 73,000 جنيه.']);
App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$buyer->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$bid,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>'عايز اشتريها كاش دلوقتي']);
$fake2=new FakeAiProvider;$fake2->queue(new AiResponse([], [['id'=>'cash-confirm','name'=>'handoff_to_human','args'=>['reason'=>'cash_purchase_confirmed','note'=>'cash buyer','motorcycle_id'=>26,'request_quote'=>'عايز اشتريها كاش دلوقتي']]],'STOP',[],'test',null,1));$fake2->queue(new AiResponse(['{"approved":true,"reason":"explicit purchase after quoted cash price"}'],[],'STOP',[],'test',null,1));$app->instance(AiProvider::class,$fake2);
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($bid));if($buyer->fresh()->status!=='awaiting_agent'||$r['messages']!==[config('agent.cash.purchase_handoff_message')])throw new RuntimeException('Confirmed buyer flow failed: '.json_encode($r));echo "PASS real runner queues confirmed cash buyer and returns dashboard notice\n";
$r=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($bid));if(!($r['no_reply']??false)||$r['messages']!==[])throw new RuntimeException('Bot spoke while handed off');echo "PASS real runner stays silent after cash handoff\n";
}finally{DB::rollBack();echo "Synthetic records rolled back; no WhatsApp delivery invoked\n";}
