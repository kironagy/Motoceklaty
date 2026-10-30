<?php
if(($argv[1]??'')!=='--apply'){fwrite(STDERR,"Usage: php scripts/deploy-id-income-policy.php --apply\n");exit(2);}
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use App\Domain\Teaching\LessonSync;use Illuminate\Support\Facades\DB;
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);
$path=$dir.'/id-income-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';file_put_contents($path,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get(),'document_types'=>DB::table('document_types')->whereIn('key',['national_id_front','national_id_back'])->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($path,0600);
$policy=trim(file_get_contents(resource_path('agent/policies/id-occupation-income-question.md')));
DB::transaction(function()use($policy){
 $instructions=app(AgentInstructions::class);$current=$instructions->current();$base=LessonSync::withoutSection($current['text']);
 $base=str_replace('`CUSTOMER_TYPE_NOT_STATED` = اسأل "حضرتك بتشتغل إيه بالظبط؟" عن اللي هيقدّم، من غير ما تعدّد أنواع.','`CUSTOMER_TYPE_NOT_STATED` = اسأل "حضرتك موظف ولا معاش ولا صاحب نشاط ولا دخل حر؟" عن اللي هيقدّم إذا نوع الدخل غير معروف.',$base);
 $base=str_replace('ولو ما قالش شغله اسأل "حضرتك بتشتغل إيه؟" من غير أرقام.','ولو نوع دخله غير معروف اسأل "حضرتك موظف ولا معاش ولا صاحب نشاط ولا دخل حر؟" من غير أرقام.',$base);
 if(!str_contains($base,$policy))$base=rtrim($base)."\n\n".$policy;
 $text=app(LessonSync::class)->withLessons($base);if(trim($text)!==trim($current['text']))$instructions->publish($text,'فصل مهنة البطاقة عن العمل الحالي وإضافة دخل حر لسؤال نوع الدخل');
 $review=(string)config('agent.safety.review_instructions');if(!str_contains($review,$policy))AgentSettings::save(['safety.review_instructions'=>rtrim($review)."\n\n".$policy]);
 $note='المهنة المطبوعة بيانات تاريخية فقط؛ طالب أو بدون عمل ليست سببًا لرفض البطاقة ولا إثباتًا لعدم العمل حاليًا. افحص جودة المستند والهوية والصلاحية، واقرأ occupation كما هو دون استنتاج حالة العمل الحالية.';
 $type=App\Models\DocumentType::where('key','national_id_back')->firstOrFail();if(!str_contains($type->description_for_ai,$note))$type->update(['description_for_ai'=>rtrim($type->description_for_ai)."\n".$note]);
});
echo 'Active instructions: '.app(AgentInstructions::class)->current()['version'].PHP_EOL;
