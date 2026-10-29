<?php
if(($argv[1]??'')!=='--apply')exit("Use --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use Illuminate\Support\Facades\DB;
$backup='/root/motobot-age-selection-backup-20261029/policy.json';if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);
if(!file_exists($backup)){file_put_contents($backup,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get()->all(),'settings'=>DB::table('agent_settings')->get()->all()],JSON_UNESCAPED_UNICODE));chmod($backup,0600);}
DB::transaction(function(){
$i=app(AgentInstructions::class);$current=$i->current();$addition=trim(file_get_contents(resource_path('agent/policies/age-model-no-unknown.md')));
if(!str_contains($current['text'],$addition))$i->publish($current['text']."\n\n".$addition,'أولوية رفض السن قبل العمل وربط الموديل بالماركة لا السعر ومنع جملة عدم المعرفة');
AgentSettings::save([
'safety.reply_guard_mode'=>'advisory',
'safety.unknown_reply'=>'حصل عطل مؤقت أثناء الرد. ممكن تبعت سؤالك كمان مرة؟',
'safety.banned_reply_pattern'=>'/(?:ما\\s*عنديش|معنديش|لا\\s*أملك|لا\\s*املك|ليس\\s*لدي)[^.!؟?\\n]{0,60}معلوم(?:[ةه]|ات)[^.!؟?\\n]{0,30}(?:مؤكد[ةه]|موكد[ةه]|موثق[ةه])/u',
'safety.banned_reply_repair'=>'الجملة الجاهزة عن عدم وجود معلومة مؤكدة ممنوعة. أعد الإجابة عن سؤال العميل الحالي بالمعلومات المسجلة. استخدم بحث الموديل والتفاصيل أو requests حسب السؤال، ولا تغير الموديل لمجرد السعر. إذا التفصيل يحتاج تحديدًا اسأل سؤالًا محددًا عنه. لا تكرر عبارة عدم المعرفة ولا تخترع معلومة.',
'eligibility.age_stop_instructions'=>'السن المصرح به غير مستوفٍ: لا تسأل عن عمل نفس الشخص أو نوع شغله أو تأمينه. وضح شرط السن واعرض شخصًا آخر مستوفيًا الشروط يقدم باسمه وبمستنداته، مش شرط من أهله. أسئلة الشغل تخص الشخص البديل بعد التأكد أنه مستوفٍ السن.'
]);
});
echo app(AgentInstructions::class)->current()['version'],"\n";
