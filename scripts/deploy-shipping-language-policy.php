<?php
if(($argv[1]??'')!=='--apply'){fwrite(STDERR,"Usage: php scripts/deploy-shipping-language-policy.php --apply\n");exit(2);}
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use App\Domain\Teaching\LessonSync;use Illuminate\Support\Facades\DB;
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/shipping-language-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';file_put_contents($path,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($path,0600);
$policy=trim(file_get_contents(resource_path('agent/policies/shipping-sale-language.md')));
DB::transaction(function()use($policy){
 $instructions=app(AgentInstructions::class);$current=$instructions->current();$base=str_replace('الترخيص وتكلفته، التوصيل، الدفع','الترخيص وتكلفته، الدفع',LessonSync::withoutSection($current['text']));
 if(!str_contains($base,$policy))$base=rtrim($base)."\n\n".$policy;
 $text=app(LessonSync::class)->withLessons($base);if(trim($text)!==trim($current['text']))$instructions->publish($text,'تثبيت الشحن وأمان بدون حظر بيع ومنع الرد المسيء أو غير المفهوم');
 $review=(string)config('agent.safety.review_instructions');if(!str_contains($review,$policy))$review=rtrim($review)."\n\n".$policy;
 $handoff=(string)config('agent.handoff.tool_description');$extra='استثناء شحن الكاش: cash_shipping_request مع request_quote حرفي من السؤال الحالي وسياق كاش مؤكد، بدون اشتراط موديل أو عرض سعر أو تأكيد شراء. لا تحول عميل التقسيط أو طريقة دفع مجهولة.';if(!str_contains($handoff,$extra))$handoff=rtrim($handoff)."\n".$extra;
 AgentSettings::save([
 'cash.shipping_handoff'=>true,
 'cash.shipping_review_instructions'=>trim(file_get_contents(resource_path('agent/policies/cash-shipping-review.md'))),
 'cash.shipping_handoff_message'=>'الشحن متاح. تم تحويل استفسارك لزميل من قسم الكاش عشان يتابع معاك تفاصيل الشحن والاستلام.',
 'cash.shipping_handoff_topic'=>'استفسار شحن — قسم الكاش',
 'handoff.tool_description'=>$handoff,'safety.review_instructions'=>$review,
 'safety.language_enabled'=>true,
 'safety.language_review_instructions'=>trim(file_get_contents(resource_path('agent/policies/language-review.md'))),
 'safety.language_banned_pattern'=>'/(?:مبغوت[ةه]|مبغوط[ةه]|يا\s+(?:غبي|حمار|اهبل|أهبل))/u',
 'safety.language_repair_instructions'=>'أعد الرد كاملًا بلغة مصرية محترمة ومفهومة بدون شتائم أو سخرية أو هزار ثقيل أو ألفاظ مخترعة. اذكر العلاقة المؤكدة أو الشخص اللي هيقدّم، ولا تختلق وصفًا أو معلومة. أجب السؤال من مصادره فقط.',
 'safety.language_safe_reply'=>'ممكن توضّحلي طلبك أكتر عشان أرد عليك بدقة؟',
 ]);
});
echo 'Active instructions: '.app(AgentInstructions::class)->current()['version'].PHP_EOL;
