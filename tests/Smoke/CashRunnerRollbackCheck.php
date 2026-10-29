<?php
if(($argv[1]??'')!=='--rollback-check'){fwrite(STDERR,"Pass --rollback-check; synthetic data are rolled back and delivery is never invoked.\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use App\Agent\Providers\{AiProvider,AiResponse,FakeAiProvider};
config(['agent.cash.inventory_enabled'=>true,'agent.cash.tool_description'=>file_get_contents(resource_path('agent/policies/cash-tool.md')),'agent.cash.unavailable_message'=>trim(file_get_contents(resource_path('agent/policies/cash-unavailable.md'))),'agent.cash.handoff_topic'=>'اختبار صناعي للكاش']);
$stock=app(App\Domain\Catalog\CashInventoryService::class)->forMachine(31);if(!$stock['available'])throw new RuntimeException('Fixture model stock changed; choose an available fixture');
DB::beginTransaction();try{
$bot=App\Models\WhatsappBot::where('is_active',true)->firstOrFail();
foreach([true,false]as$available){
 $jid='sim-cash-'.bin2hex(random_bytes(5));$customer=App\Models\Customer::create(['whatsapp_bot_id'=>$bot->id,'jid'=>$jid,'phone'=>$jid]);$c=App\Models\WhatsappConversation::create(['whatsapp_bot_id'=>$bot->id,'customer_id'=>$customer->id,'phone'=>$jid,'status'=>'open']);
 $id=DB::table('whatsapp_message_jobs')->insertGetId(['whatsapp_bot_id'=>$bot->id,'whatsapp_conversation_id'=>$c->id,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
 $machine=$available?31:16;$question=$available?'عايز اشتري فيجوري VLR200 كاش موجودة فين وباي لون؟':'عايز اشتري بينيلي VLR 150 كاش';
 App\Models\WhatsappMessage::create(['whatsapp_conversation_id'=>$c->id,'whatsapp_bot_id'=>$bot->id,'turn_id'=>$id,'direction'=>'incoming','sender_type'=>'customer','type'=>'text','text'=>$question]);
 $fake=new FakeAiProvider;$fake->queue(new AiResponse([], [['id'=>'stock','name'=>'check_cash_inventory','args'=>['motorcycle_id'=>$machine]]],'STOP',[],'test',null,1));
 $reply='المكنة موجودة في فرع جسر السويس بالأحمر والأزرق، وفي فرع العمرانية بالرمادي الغامق والأبيض. تقدر تروح تستلمها من الفرع اللي فيه اللون اللي عايزه.';
 if($available)$fake->queue(new AiResponse([], [['id'=>'reply','name'=>'send_reply','args'=>['messages'=>[$reply]]]],'STOP',[],'test',null,1));
 $fake->queue(new AiResponse(['{"approved":true,"reason":"synthetic verified evidence"}'],[],'STOP',[],'test',null,1));$app->instance(AiProvider::class,$fake);
 $result=app(App\Agent\Runtime\AgentRunner::class)->run(DB::table('whatsapp_message_jobs')->find($id));
 if($available){if(($result['messages'][0]??'')!==$reply)throw new RuntimeException('Sourced stock reply was blocked: '.json_encode($result));if($c->fresh()->status!=='open')throw new RuntimeException('Available stock unexpectedly handed off');echo "PASS runner sends correct branch/color/pickup reply for available model\n";}
 else{if($c->fresh()->status!=='awaiting_agent')throw new RuntimeException('Zero-stock cash did not enter waiting list');if(!App\Models\Handoff::where('conversation_id',$c->id)->where('reason','cash_stock_unavailable')->exists())throw new RuntimeException('Cash handoff missing');if(!str_contains($result['messages'][0]??'','مش متاحة حاليًا'))throw new RuntimeException('Customer notice missing');echo "PASS runner returns configured notice and queues unavailable cash conversation\n";}
}
}finally{DB::rollBack();echo "Synthetic records and notifications rolled back; no WhatsApp delivery invoked\n";}
