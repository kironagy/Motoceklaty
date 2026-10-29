<?php
if(($argv[1]??'')!=='--apply')exit("Use --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use Illuminate\Support\Facades\DB;
$backup='/root/motobot-advisory-backup-20261029/policy.json';
if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);
if(!file_exists($backup)){file_put_contents($backup,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get()->all(),'settings'=>DB::table('agent_settings')->get()->all()],JSON_UNESCAPED_UNICODE));chmod($backup,0600);}
DB::transaction(function(){
$i=app(AgentInstructions::class);$current=$i->current();$addition=trim(file_get_contents(resource_path('agent/policies/advisory-replies.md')));
$text=$current['text'];$old='- الرد على سؤال محدد يكون في حدوده: سعر مطلوب فقط = سعره، من غير صفات غير موثقة مثل توفير البنزين أو مناسب للشغل، ومن غير بدء تقسيط أو تقديم من نفسك.';
$text=str_replace($old,'- سؤال السعر فقط يُجاب بسعره. الاستفسار العام عن موديل يُجاب بمواصفاته المسجلة ثم سعره، دون صفات مختلقة أو تقديم لم يطلبه العميل.',$text);
if(!str_contains($text,$addition))$i->publish($text."\n\n".$addition,'الرد الطبيعي: حماية النص ملاحظات فقط، عدم المعرفة للمعلومة المفقودة فعلًا، ومواصفات الموديل ثم سعره');
AgentSettings::save(['safety.reply_guard_mode'=>'advisory','fallback.message'=>'حصل عطل مؤقت أثناء الرد. ممكن تبعت سؤالك كمان مرة؟']);
});
echo app(AgentInstructions::class)->current()['version'],"\n";
