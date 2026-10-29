<?php
// Explicit, idempotent deployment. Back up database policy before touching it.
if (($argv[1] ?? '') !== '--apply') { fwrite(STDERR,"Usage: php scripts/deploy-bot-policy.php --apply\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};
use Illuminate\Support\Facades\DB;
$backup=trim(file_get_contents('/tmp/motogate-bot-backup-path'));
if (!is_dir($backup) || !is_writable($backup)) throw new RuntimeException('Backup directory required');
$snapshot=['instructions'=>DB::table('agent_instruction_versions')->get()->all(),'settings'=>DB::table('agent_settings')->get()->all(),'models'=>DB::table('gemini_api_key_models')->get()->all()];
$path=$backup.'/database-policy-before.json';if(!file_exists($path)){file_put_contents($path,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($path,0600);}
$review=file_get_contents(resource_path('agent/policies/evidence-review.md'));
$rules=[['id'=>'unrecorded_gift','pattern'=>'/(?:بنسلم|بنديك|هتاخد|معاها|معاه|عندنا|بنقدم|بيجي|بتيجي)[^\n.!؟?]{0,120}(?:خوذ|شنط|صندوق|هدي|هدايا)|(?:خوذ[ةه]|شنط[ةه]|صندوق)[^\n.!؟?]{0,70}(?:هدي[ةه]|مجان)/u','evidence_key'=>'approved_gifts']];
DB::transaction(function()use($review,$rules){
    $instructions=app(AgentInstructions::class);$current=$instructions->current();
    $addition=trim(file_get_contents(resource_path('agent/policies/reply-safety.md')));
    if(!str_contains($current['text'],$addition)){
        $base=App\Domain\Teaching\LessonSync::withoutSection($current['text']);
        $text=app(App\Domain\Teaching\LessonSync::class)->withLessons(rtrim($base)."\n\n".$addition);
        $instructions->publish($text,'تعديلات البوت فقط: تثبيت مصدر الرد والمقدم والهدايا والتحويل بطلب العميل');
    }
    AgentSettings::save(['safety.no_zero_upfront_hint'=>trim(file_get_contents(resource_path('agent/policies/no-zero-upfront.md'))),'safety.enabled'=>true,'safety.review_instructions'=>$review,'safety.unknown_reply'=>'ما عنديش معلومة مؤكدة أقدر أقولها عن النقطة دي.','safety.claim_rules'=>json_encode($rules,JSON_UNESCAPED_UNICODE),'handoff.customer_only'=>true,'handoff.tool_description'=>'التحويل فقط إذا طلب العميل صراحة موظفًا أو مكالمة بشرية. request_quote لازم من كلامه الحالي حرفيًا. الشكوى والخصم وعدم معرفة المعلومة ليست طلب تحويل.','keys.pool'=>'balanced']);
    $defaults=(require config_path('gemini.php'))['providers']['gemini']['default_models'];
    foreach(App\Models\GeminiApiKey::where('provider','gemini')->where('is_active',true)->get() as $key){
        $key->models()->where('provider','!=','gemini')->update(['is_active'=>false]);
        foreach($defaults as $model){$key->models()->firstOrCreate(['model_code'=>$model['model_code']],$model+['provider'=>'gemini','is_active'=>true,'requests_today'=>0,'requests_this_minute'=>0,'tokens_this_second'=>0]);}
    }
});
AgentSettings::apply();
echo json_encode(['instruction_version'=>app(AgentInstructions::class)->current()['version'],'review'=>config('agent.safety.enabled'),'handoff_customer_only'=>config('agent.handoff.customer_only'),'keys_pool'=>config('agent.keys.pool'),'backup'=>$backup],JSON_UNESCAPED_UNICODE),"\n";
