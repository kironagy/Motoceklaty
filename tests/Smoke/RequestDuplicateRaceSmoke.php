<?php
if(!in_array('--isolated-test-only',$argv,true))exit("Use only with an isolated motogate_codex_test_ database\n");
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\InstallmentRequest;
if(!str_starts_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(),'motogate_codex_test_'))throw new RuntimeException('Production database forbidden');
$data=['applicant_name'=>'Synthetic duplicate race test','applicant_national_id'=>'29001011234567','applicant_phone'=>'01000000123','request_type'=>'normal','status'=>'pending','machine_id'=>(int)($argv[3]??0),'installment_type'=>'Synthetic test','months'=>12];
if(in_array('--child',$argv,true)){
 try{InstallmentRequest::withoutEvents(fn()=>InstallmentRequest::create($data));echo 'CREATED';}
 catch(Illuminate\Validation\ValidationException $e){echo 'DUPLICATE';}exit;
}
InstallmentRequest::withoutEvents(fn()=>InstallmentRequest::where('applicant_name',$data['applicant_name'])->forceDelete());
$brand=App\Models\Brand::withoutEvents(fn()=>App\Models\Brand::create(['name'=>'Synthetic race brand','image'=>'test.jpg']));
$machine=App\Models\Machine::withoutEvents(fn()=>App\Models\Machine::create(['name'=>'Synthetic race machine','brand_id'=>$brand->id,'cash_price'=>100,'installment_price'=>100,'is_active'=>false,'availability'=>'out_of_stock','type'=>'normal']));
$processes=[];$bootstrap=dirname(__DIR__).'/intake-test-bootstrap.php';
for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$bootstrap,__FILE__,'--isolated-test-only','--child',(string)$machine->id],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$p,$pipes];}
$out=[];foreach($processes as[$p,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Race child failed: '.$err);}
sort($out);if($out!==['CREATED','DUPLICATE']||InstallmentRequest::where('applicant_name',$data['applicant_name'])->count()!==1)throw new RuntimeException('Concurrent duplicate guard failed: '.json_encode($out));
InstallmentRequest::withoutEvents(fn()=>InstallmentRequest::where('applicant_name',$data['applicant_name'])->forceDelete());
Illuminate\Support\Facades\DB::table('machines')->where('id',$machine->id)->delete();Illuminate\Support\Facades\DB::table('brands')->where('id',$brand->id)->delete();
echo "PASS concurrent identity intake creates exactly one request; synthetic record removed\n";
