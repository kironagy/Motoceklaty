<?php
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=json_decode(file_get_contents(resource_path('agent/policies/intake-quality-settings.json')),true);foreach($settings['settings']as$k=>$v)config(['agent.'.$k=>$v]);
$review=new class extends App\Agent\Runtime\EvidenceReview {public bool $allow=false;public function judge(string $task,array $data):bool{return $this->allow;}};
$app->instance(App\Agent\Runtime\EvidenceReview::class,$review);$n=0;$ok=function($v,$name)use(&$n){if(!$v)throw new RuntimeException($name);echo "PASS $name\n";$n++;};
$ok($review->customerRequestedHandoff('اي داأنا عايز موظف يكلمنيعشن احجز',"أنا عايز موظف يكلمني\nعشن احجز"),'real current request survives model quote concatenation');
$ok(!$review->customerRequestedHandoff('موظف','أنا موظف في المترو'),'job statement is not staff request');
$ok(!$review->customerRequestedHandoff('موظف','مش عايز موظف يكلمني'),'negative staff request is not authorized');
$ok(!$review->customerRequestedHandoff('موظف','عايز موظف يقدم بدالي'),'alternative applicant is not staff handoff');
$ok($review->customerRequestedHandoff('wrong','ممكن اكلم موظف حقيقي؟'),'explicit person request works');
$g=app(App\Agent\Runtime\ReplyGuard::class);$c=new App\Models\WhatsappConversation(['status'=>'open']);
$ok($g->handoffClaimWithoutAction(['messages'=>['الزملاء هيتصلوا بيك في أقرب وقت']],$c,[]),'promise of colleagues blocked without successful action');
$ok(!$g->handoffClaimWithoutAction(['messages'=>['الزملاء هيتصلوا بيك']],$c,[['ok'=>true,'data'=>['handed_off'=>true]]]),'successful handoff is evidence');
config(['agent.safety.language_enabled'=>true,'agent.safety.language_banned_pattern'=>'/'.$settings['blocked_text_pattern_fragment'].'/u']);
$l=app(App\Agent\Runtime\ReplyLanguageReview::class);foreach(['send_reply{messages:}','عين عقل، هيتصلوا بيك','العنوان ${branch_address}']as$t)$ok(!$l->allows(['messages'=>[$t]]),'internal or unclear text blocked directly');
$review->allow=true;$ok($l->allows(['messages'=>['فرع عين شمس في شارع الزهراء.']]),'normal branch text allowed');
$c->status='awaiting_agent';config(['agent.handoff.customer_only'=>true]);$ok(!App\Domain\Handoff\HandoffService::botKeepsAnswering($c),'awaiting staff means silence even for call request');
echo "$n checks passed\n";
