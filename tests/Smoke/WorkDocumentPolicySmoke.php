<?php
foreach(['APP_ENV'=>'testing','APP_CONFIG_CACHE'=>'/tmp/work-policy-test-no-cache.php','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','LOG_CHANNEL'=>'stderr']as$k=>$v){putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Applications\WorkClassification;
$reader=new class extends WorkClassification {
    public array $profile=[];
    public function reading(int $conversationId,?string $quote=null):?array{return $this->profile;}
};
$reader->profile=['evidence'=>'موظف مترو الأنفاق','occupation'=>'موظف','working_now'=>'yes','customer_type'=>'employee','refused_work'=>false,'sector'=>'government','daily_labour_no_trade'=>false,'work_stated'=>true,'question'=>'none','could_be_government'=>false,'stated_monthly_income'=>0,'work_type'=>'none','relation_to_workplace'=>'works_for_someone','insured'=>'unknown','applicant_gender'=>'unknown'];
$n=0;$ok=function($v,$name)use(&$n){if(!$v)throw new RuntimeException($name);echo "PASS $name\n";$n++;};
$ok(($reader->problem(1,'employee')['code']??null)==='ASK_INSURED','government employee continues with insurance question, no blanket refusal');
config(['agent.eligibility.occupation_review_hint'=>'test-dashboard-policy']);
$reader->profile['refused_work']=true;
$r=$reader->problem(1,'employee');
$ok($r['code']==='OCCUPATION_REQUIRES_STAFF_REVIEW','flagged occupation requires staff review, no final automated decision');
$ok($r['hint']==='test-dashboard-policy','review instructions come from dashboard config');
$ok(!in_array($r['code'],WorkClassification::REFUSALS,true),'review is not a final refusal code');
$reader->profile['refused_work']=false;$reader->profile['sector']='private';
$ok(($reader->problem(1,'employee')['code']??null)==='ASK_INSURED','ordinary employee still follows normal document checks');
$policy=file_get_contents(resource_path('agent/policies/work-and-salary-documents.md'));
$ok(str_contains($policy,'محاسب قانوني غير مقبولة')&&str_contains($policy,'جهة العمل'),'document source policy distinguishes accountant certificate and employer salary slip');
$ok(str_contains($policy,'المشتري الفعلي')&&str_contains($policy,'اسم صوري'),'alternative applicant must be the actual purchaser');
echo "$n checks passed\n";
