<?php
if(($argv[1]??'')!=='--apply')exit("Use --apply\n");
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use Illuminate\Support\Facades\DB;
$backup='/root/motobot-followup-backup-20261029/policy.json';
if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);
if(!file_exists($backup)){file_put_contents($backup,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get()->all(),'settings'=>DB::table('agent_settings')->get()->all()],JSON_UNESCAPED_UNICODE));chmod($backup,0600);}
DB::transaction(function(){
$i=app(AgentInstructions::class);$current=$i->current();$addition=trim(file_get_contents(resource_path('agent/policies/followup-and-eligibility.md')));
if(!str_contains($current['text'],$addition))$i->publish($current['text']."\n\n".$addition,'متابعة الطلب ومنع التقديم المكرر وتوضيح الشغل والسن وحد التمويل');
$review=(string)config('agent.safety.review_instructions');
$extra="\nالتحية والشكر والمجاملة القصيرة لا تحتاج دليل أداة. حالة الطلب من requests في sources دليل موثق للطلب القديم لا يتطلب submit_application جديدًا. لا تقبل وعد متابعة أو اتصال غير منفذ، أو حالة تناقض requests أو رقم غير مثبت الملكية. سياسات الأهلية في التعليمات المعتمدة صالحة لتفسير الشروط؛ مبلغ قسط أو مقدم محدد يحتاج عرض الأداة.";
if(!str_contains($review,'حالة الطلب من requests'))$review.=$extra;
AgentSettings::save([
'eligibility.no_work_hint'=>'للأسف يا فندم لازم يكون عندك شغل حالي، لأن الشغل بيوضح قدرتك على سداد الأقساط وبيساعد إن الطلب يستوفي الشروط. قرار الموافقة لجهة التمويل. ينفع شخص تاني مستوفٍ شروط السن والعمل يقدم باسمه وبمستنداته، ومش شرط يكون من أهلك.',
'eligibility.other_applicant'=>'ينفع أي شخص تاني يقدم بدالك، مش شرط من أهلك؛ لازم يكون تم 21 سنة ومستوفي باقي شروط السن والعمل. الطلب يكون باسمه وببياناته ومستنداته. هو بيشتغل إيه؟',
'eligibility.other_applicant_refused'=>'اللي هيقدم لازم يستوفي شروط السن والعمل. ينفع شخص تاني مستوفٍ الشروط يقدم باسمه وبمستنداته، ومش شرط من أهلك.',
'installments.cap_explanation'=>'جهة التمويل بتمول لحد المبلغ المسموح حسب شغلك، والموديل ده أعلى منه؛ الفرق بيتدفع مقدم والباقي بيتقسط، والمصاريف الإدارية منفصلة. لو مش عايز مقدم نشوف موديل في حدود التمويل ونحسب عرضه.',
'safety.review_instructions'=>$review]);
});
echo app(AgentInstructions::class)->current()['version'],"\n";
