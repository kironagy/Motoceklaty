<?php
if(($argv[1]??'')!=='--rollback-check')exit("Use --rollback-check\n");
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};use App\Agent\Runtime\{AgentRunner,EvidenceReview,ReplyGuard};
config(['agent.safety.reply_guard_mode'=>'advisory','agent.safety.enabled'=>true]);
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();$jid='sim-advisory-'.bin2hex(random_bytes(5));$customer=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$customer->id,'phone'=>$jid,'status'=>'open']);
$id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>'لو سمحت بستفسر عن فيجوري Max 26']);
$reply='فيجوري 26 Max موتور 200 سي سي، وسعرها كاش 49,000 جنيه.';
$fake=new FakeAiProvider;$fake->queue(new AiResponse([], [['id'=>'natural-reply','name'=>'send_reply','args'=>['messages'=>[$reply]]]],'STOP',[],'test',null,1));$app->instance(AiProvider::class,$fake);
$result=app(AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id));
if(($result['messages'][0]??'')!==$reply)throw new RuntimeException('Model answer replaced: '.json_encode($result));echo "PASS model answer reaches output unchanged in advisory mode\n";
$trace=App\Models\AiTrace::where('turn_id',$id)->firstOrFail();
if($trace->status!=='done')throw new RuntimeException('Answer treated as fallback');echo "PASS reply trace is done, not fallback\n";
echo 'observations=',json_encode(collect($trace->guard_events)->pluck('code'),JSON_UNESCAPED_UNICODE),"\n";
$review=app(EvidenceReview::class);if($review->check(['messages'=>['اختبار نص غير مراجع']], '',[])!==null)throw new RuntimeException('Reviewer blocked in advisory mode');echo "PASS evidence checker does not block or require a second model call\n";
$r=(new ReflectionMethod(AgentRunner::class,'unverifiedReply'))->invoke(app(AgentRunner::class),$c,$trace,[],'LIMIT_WITHOUT_FINAL_REPLY');
if($r['messages']!==[config('agent.fallback.message')]||$r['messages']===[config('agent.safety.unknown_reply')])throw new RuntimeException('Technical failure mislabeled missing information');echo "PASS technical failure cannot emit unknown-information template\n";
if(App\Models\Handoff::where('conversation_id',$c->id)->exists())throw new RuntimeException('Advisory created a handoff');echo "PASS advisory observation creates no handoff\n";
if($review->customerRequestedHandoff('اقتباس مختلق','عايز سعر المكنة'))throw new RuntimeException('Action validation weakened');echo "PASS action quote validation remains enforced\n";
}finally{DB::rollBack();echo "Rolled back; no WhatsApp delivery invoked\n";}
