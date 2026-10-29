<?php
// Isolated method regressions: no database reads or writes, no provider calls.
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$catalog=new class extends App\Agent\Runtime\CatalogMentions {
    public function fullNames(string $text): array { return str_contains($text,'VLM') ? [26,60] : (str_contains($text,'RKV') ? [99] : []); }
};
$app->instance(App\Agent\Runtime\CatalogMentions::class,$catalog);
$guard=app(App\Agent\Runtime\ReplyGuard::class);
$invoke=fn($name,...$args)=>(new ReflectionMethod($guard,$name))->invoke($guard,...$args);
$n=0;$ok=function($value,$label)use(&$n){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";$n++;};
$stock=['machine_id'=>26,'source'=>'physical_inventory','available'=>true];
$search=['name'=>'search_motorcycles','ok'=>true,'data'=>['items'=>[
    ['id'=>26,'brand'=>'بينيلي','cash_stock'=>$stock],
    ['id'=>60,'brand'=>'فيجوري','cash_stock'=>['machine_id'=>60,'source'=>'physical_inventory','available'=>false]],
], 'ambiguous_names'=>[['بينيلي (id 26)','فيجوري (id 60)']]]];
$reply='بينيلي VLM 200 موجودة حاليا في فرع العمرانية باللون الأحمر.';
$ok($invoke('ambiguityIgnored',$reply,[$search],'عايز بينيلي vlm كاش')===null,'explicit customer brand resolves broad search');
$ok($invoke('ambiguityIgnored',$reply,[$search],'عايز VLM كاش')!==null,'brandless request still asks clarification');
$ok($invoke('ambiguityIgnored',$reply,[$search],'عايز فيجوري VLM كاش')!==null,'wrong brand remains blocked');
$ok($invoke('hasLiveStockForReply',$reply,[$search]),'physical stock embedded in search accepted');
$ok(!$invoke('hasLiveStockForReply',$reply,[]),'availability without evidence blocked');
$ok(!$invoke('hasLiveStockForReply','فيجوري VLM موجودة في فرع العمرانية',[$search]),'other brand cannot borrow available stock');
$ok(!$invoke('hasLiveStockForReply','بينيلي RKV موجودة في فرع العمرانية',[$search]),'other model cannot borrow stock');
$ok($invoke('hasLiveStockForReply',$reply.' بخصوص فيجوري VLM فهي مش متوفرة عندنا.',[$search]),'negative stock statement is not a positive availability claim');
$ok(!$invoke('claimsStockOrCheck',$reply,true),'verified availability wording passes');
$ok($invoke('claimsStockOrCheck','هحجزلك ميعاد للمعاينة',true),'stock does not permit fabricated reservation');
$search['data']['items'][0]['cash_stock']['source']='catalog';
$ok(!$invoke('hasLiveStockForReply',$reply,[$search]),'catalog availability is not physical stock');
echo "$n checks passed\n";
