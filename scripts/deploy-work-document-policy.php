<?php
// Explicit, idempotent dashboard update with a recoverable policy snapshot.
if (($argv[1] ?? '') !== '--apply') { fwrite(STDERR,"Usage: php scripts/deploy-work-document-policy.php --apply\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use App\Domain\Teaching\LessonSync;use Illuminate\Support\Facades\DB;
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);
$path=$dir.'/work-document-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
file_put_contents($path,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get(),'lessons'=>DB::table('bot_lessons')->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($path,0600);
$policy=trim(file_get_contents(resource_path('agent/policies/work-and-salary-documents.md')));
DB::transaction(function()use($policy){
    $instructions=app(AgentInstructions::class);$current=$instructions->current();
    // Preserve lesson records for audit; deactivate only the outdated blanket rule.
    DB::table('bot_lessons')->where('is_active',true)->where(function($q){$q->where('rule','like','%جهة حكومية%')->orWhere('rule','like','%الشغل الحكومي%');})->where('rule','like','%هيترفض%')->update(['is_active'=>false]);
    $base=LessonSync::withoutSection($current['text']);
    $base=preg_replace('/^.*شغل حكومي \(أمين شرطة.*$/mu','- لا تصدر حكم تمويل نهائي آلي بسبب الوظيفة. اتبع قسم سياسة الوظائف وإثبات الدخل الأحدث.',$base);
    if(!str_contains($base,$policy))$base=rtrim($base)."\n\n".$policy;
    $text=app(LessonSync::class)->withLessons($base);
    if(trim($text)!==trim($current['text']))$instructions->publish($text,'تصحيح سياسة مفردات المرتب والوظائف: إزالة تعميم الحكومي ومنع القرار النهائي الآلي');
    $review=(string)config('agent.safety.review_instructions');
    if(!str_contains($review,$policy))$review=rtrim($review)."\n\n".$policy;
    AgentSettings::save(['eligibility.other_applicant'=>'يمكن شخص آخر يقدّم بطلب مستقل باسمه إذا كان هو المشتري الفعلي والمسؤول عن الأقساط، وطلبه يخضع للشروط والمراجعة. لا تستخدم اسمًا صوريًا أو بيانات شخص لتمويل مشتريات غيره.','eligibility.occupation_review_hint'=>'الحالة تحتاج مراجعة موظف قبل استكمال التقديم؛ لا تصدر قرار رفض أو قبول نهائي، ولا تحول المحادثة تلقائيًا. اشرح سياسة الداشبورد والطلب المستقل للمشتري الفعلي عند سؤال العميل عن البديل.','safety.review_instructions'=>$review]);
});
echo 'Active instructions: '.app(AgentInstructions::class)->current()['version'].PHP_EOL;
