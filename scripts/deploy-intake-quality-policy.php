<?php
if(($argv[1]??'')!=='--apply')exit("Use --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Settings\{AgentInstructions,AgentSettings};use App\Domain\Teaching\LessonSync;use Illuminate\Support\Facades\DB;
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/intake-quality-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';file_put_contents($file,json_encode(['instructions'=>DB::table('agent_instruction_versions')->get(),'settings'=>DB::table('agent_settings')->get()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($file,0600);
$policy=trim(file_get_contents(resource_path('agent/policies/intake-quality.md')));
$policySettings=json_decode(file_get_contents(resource_path('agent/policies/intake-quality-settings.json')),true,512,JSON_THROW_ON_ERROR);
DB::transaction(function()use($policy,$policySettings){
 $i=app(AgentInstructions::class);$current=$i->current();$base=LessonSync::withoutSection($current['text']);if(!str_contains($base,$policy))$base=rtrim($base)."\n\n".$policy;$text=app(LessonSync::class)->withLessons($base);if($text!==$current['text'])$i->publish($text,'إصلاح الكتالوج والتحويل وتسرب المتغيرات وتعليمات الفورم');
 $review=(string)config('agent.safety.review_instructions');if(!str_contains($review,$policy))$review=rtrim($review)."\n\n".$policy;
 $pattern=(string)config('agent.safety.language_banned_pattern');$extra=$policySettings['blocked_text_pattern_fragment'];
 if(!str_contains($pattern,'send_reply')){if($pattern==='')$pattern='/'.$extra.'/u';else{$d=$pattern[0];$end=strrpos($pattern,$d);if($end===0||@preg_match($pattern,'')===false)throw new RuntimeException('Invalid language pattern');$pattern=$d.'(?:'.substr($pattern,1,$end-1).')|(?:'.$extra.')'.$d.substr($pattern,$end+1);}}
 if(@preg_match($pattern,'')===false)throw new RuntimeException('Combined language policy is invalid');
 AgentSettings::save($policySettings['settings']+['safety.review_instructions'=>$review,'safety.language_banned_pattern'=>$pattern]);
});
Illuminate\Support\Facades\Cache::forget('catalog.index_lines.v2');
echo app(AgentInstructions::class)->current()['version'].PHP_EOL;
