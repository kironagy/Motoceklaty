<?php
if(($argv[1]??'')!=='--apply')exit("Use --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use Illuminate\Support\Facades\DB;
$backup='/root/motobot-persistent-age-backup-20261029/policy.json';if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);
if(!file_exists($backup)){file_put_contents($backup,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get()->all(),'settings'=>DB::table('agent_settings')->get()->all()],JSON_UNESCAPED_UNICODE));chmod($backup,0600);}
DB::transaction(function(){
$i=app(AgentInstructions::class);$current=$i->current();$addition=trim(file_get_contents(resource_path('agent/policies/persistent-age-gate.md')));
if(!str_contains($current['text'],$addition))$i->publish($current['text']."\n\n".$addition,'استمرار رفض السن عبر الرسائل وعدم إعادة أسئلة العمل عند ذكر المهنة');
AgentSettings::save(['eligibility.age_block_message'=>'فاهم حضرتك، لكن طبيعة الشغل ما بتغيرش شرط السن اللي وضحناه؛ لازم صاحب الطلب يكون مستوفي شروط السن للتقسيط. ينفع أي شخص تاني مستوفي شروط السن والعمل يقدم باسمه وبمستنداته، ومش شرط يكون من أهلك.']);
});
echo app(AgentInstructions::class)->current()['version'],"\n";
