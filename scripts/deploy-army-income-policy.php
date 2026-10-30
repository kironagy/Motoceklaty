<?php
if(($argv[1]??'')!=='--apply'){fwrite(STDERR,"Usage: php scripts/deploy-army-income-policy.php --apply\n");exit(2);}
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use App\Domain\Teaching\LessonSync;use Illuminate\Support\Facades\DB;
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);
$path=$dir.'/army-policy-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';file_put_contents($path,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($path,0600);
$policy=trim(file_get_contents(resource_path('agent/policies/army-police-income.md')));
$replace=function(string $text):string{
 return preg_replace('/^- القائمة التي يذكرها صاحب العمل في سياسته: ضابط\/ظابط.*$/mu','- قائمة سياسة الوظائف السابقة تخص ضباط الشرطة وأمناء الشرطة والرتب الشرطية والجهات السيادية والقضاء والمحاماة والمستشار القضائي. ضباط الجيش لهم الاستثناء الأحدث بكشف حساب آخر 6 شهور. الرتبة وحدها لا تحدد الجهة، وعند اللبس اسأل عن طبيعة الوظيفة.',$text)??$text;
};
DB::transaction(function()use($policy,$replace){
 $instructions=app(AgentInstructions::class);$current=$instructions->current();$base=$replace(LessonSync::withoutSection($current['text']));
 if(!str_contains($base,$policy))$base=rtrim($base)."\n\n".$policy;
 $text=app(LessonSync::class)->withLessons($base);if(trim($text)!==trim($current['text']))$instructions->publish($text,'تمييز ضابط الجيش بكشف حساب آخر 6 شهور عن سياسة الشرطة');
 $review=$replace((string)config('agent.safety.review_instructions'));if(!str_contains($review,$policy))$review=rtrim($review)."\n\n".$policy;
 AgentSettings::save(['safety.review_instructions'=>$review]);
});
echo 'Active instructions: '.app(AgentInstructions::class)->current()['version'].PHP_EOL;
