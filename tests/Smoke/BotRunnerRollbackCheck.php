<?php
if (($argv[1] ?? '') !== '--rollback-check') { fwrite(STDERR, "Pass --rollback-check for a synthetic transaction with no delivery.\n"); exit(2); }
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};
config(['agent.safety.reply_guard_mode'=>'enforce']);
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();$jid='sim-policy-'.bin2hex(random_bytes(5));
$customer=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);
$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$customer->id,'phone'=>$jid,'status'=>'open']);
$id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>'فيه اي هدايا مع الاسكوتر؟']);
$fake=new FakeAiProvider;for($i=0;$i<2;$i++)$fake->queue(new AiResponse([], [['id'=>'reply-'.$i,'name'=>'send_reply','args'=>['messages'=>['إحنا بنسلمهالك بالخوذة والشنطة هدية من المعرض']]]],'STOP',[],'test-model',null,1));
$app->instance(AiProvider::class,$fake);$result=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id));
if($result['messages']!==[config('agent.safety.unknown_reply')])throw new RuntimeException('Invented gift escaped');
if(App\Models\Handoff::where('conversation_id',$c->id)->exists())throw new RuntimeException('Automatic handoff created');
echo "PASS real runner blocks invented gift after repair and does not hand off\n";
echo 'trace=',json_encode(App\Models\AiTrace::where('turn_id',$id)->first(['status','error_code'])),"\n";
}finally{DB::rollBack();echo "Synthetic customer, job and trace rolled back; no delivery invoked\n";}
