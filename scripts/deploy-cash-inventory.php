<?php
if (($argv[1] ?? '') !== '--apply') { fwrite(STDERR,"Usage: php scripts/deploy-cash-inventory.php --apply\n");exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use Illuminate\Support\Facades\DB;
$backup=trim(file_get_contents('/tmp/motogate-cash-backup-path'));if(!is_dir($backup))throw new RuntimeException('Backup required');
if(!file_exists($backup.'/policy-before.json')){file_put_contents($backup.'/policy-before.json',json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($backup.'/policy-before.json',0600);}
DB::transaction(function(){
 $service=app(AgentInstructions::class);$current=$service->current();$addition=trim(file_get_contents(resource_path('agent/policies/cash-inventory.md')));
 if(!str_contains($current['text'],$addition)){$base=App\Domain\Teaching\LessonSync::withoutSection($current['text']);$service->publish(app(App\Domain\Teaching\LessonSync::class)->withLessons($base."\n\n".$addition),'البوت فقط: مخزون الكاش والفروع والألوان والتحويل عند نفاد القطع');}
 $review=(string)config('agent.safety.review_instructions');$extra=trim(file_get_contents(resource_path('agent/policies/cash-evidence-review.md')));if(!str_contains($review,$extra))$review.="\n\n".$extra;
 AgentSettings::save(['cash.inventory_enabled'=>true,'cash.tool_description'=>trim(file_get_contents(resource_path('agent/policies/cash-tool.md'))),'cash.unavailable_message'=>trim(file_get_contents(resource_path('agent/policies/cash-unavailable.md'))),'cash.handoff_topic'=>'طلب كاش: الموديل غير متاح بالمخزون','safety.review_instructions'=>$review]);
});
AgentSettings::apply();echo json_encode(['version'=>app(AgentInstructions::class)->current()['version'],'cash_inventory'=>config('agent.cash.inventory_enabled'),'backup'=>$backup],JSON_UNESCAPED_UNICODE),"\n";
