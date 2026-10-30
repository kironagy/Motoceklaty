<?php
foreach(['APP_ENV'=>'testing','APP_CONFIG_CACHE'=>'/tmp/all-branches-test-cache.php','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','LOG_CHANNEL'=>'stderr']as$k=>$v){putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Schema,DB};
if(DB::connection()->getDriverName()!=='sqlite'||config('database.connections.sqlite.database')!==':memory:')throw new RuntimeException('Unsafe DB');
Schema::create('branches',function($t){$t->id();$t->string('name');$t->boolean('is_active');$t->integer('sort');$t->string('governorate');$t->string('city');$t->text('address');$t->text('map_url');$t->json('phones')->nullable();$t->json('working_hours')->nullable();$t->json('services')->nullable();});
foreach([['جسر السويس','cairo',true],['عين شمس','cairo',true],['العمرانية','giza',true],['الخصوص','qalyubia',true],['فرع مغلق','cairo',false]]as$i=>[$name,$gov,$active])DB::table('branches')->insert(['name'=>$name,'is_active'=>$active,'sort'=>$i,'governorate'=>$gov,'city'=>$name,'address'=>'عنوان '.$name,'map_url'=>'https://maps.app.goo.gl/test'.$i]);
$tool=app(App\Agent\Tools\GetBranchInformationTool::class);
$ctx=new App\Agent\Tools\ToolContext(1,1,null,1,1,new App\Agent\Runtime\TurnResultBuilder);
$n=0;$ok=function($v,$s)use(&$n){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";$n++;};
foreach([[],['governorate'=>'cairo'],['governorate'=>'cairo','city'=>'جسر السويس']]as$args){$r=$tool->execute($args,$ctx)->toArray();$ok($r['ok']&&count($r['data']['branches'])===4,'all active branches returned despite optional area filter');}
$rows=$r['data']['branches'];$ok(!in_array('فرع مغلق',array_column($rows,'name')),'inactive branch excluded');
$ok(array_column($rows,'name')===['جسر السويس','عين شمس','العمرانية','الخصوص'],'dashboard branch ordering retained');
$ok(count(array_filter($rows,fn($b)=>filled($b['address'])&&filled($b['map_url'])))===4,'every branch has its source address and map');
config(['agent.branches.tool_description'=>'dashboard-policy']);$ok($tool->description()==='dashboard-policy','tool guidance comes from dashboard');
echo "$n checks passed\n";
