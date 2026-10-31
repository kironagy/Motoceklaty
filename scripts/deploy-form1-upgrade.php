<?php
if(($argv[1]??'')!=='--apply')exit("Usage: php scripts/deploy-form1-upgrade.php --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{DynamicForm,FormLink};use Illuminate\Support\Facades\DB;
$schema=json_decode(file_get_contents(resource_path('forms/motogate-form1-v2.json')),true,512,JSON_THROW_ON_ERROR);
$dir=storage_path('app/private/policy-backups');if(!is_dir($dir))mkdir($dir,0700,true);
DB::transaction(function()use($schema,$dir){
 App\Models\DocumentType::firstOrCreate(['key'=>'vehicle_license'],['label'=>'رخصة العربية','description_for_ai'=>'رخصة تسيير العربية أو المركبة، وليست رخصة قيادة الشخص. اقرأ اسم المالك وتاريخ انتهاء الرخصة إن ظهر، ولا تستنتج اشتراكًا في تطبيق نقل ذكي.','accepted_mimes'=>['image/jpeg','image/png','application/pdf'],'extraction_fields'=>[],'optional_fields'=>['full_name','license_expiry_date'],'validation_rules'=>[],'is_active'=>true]);
 $form=DynamicForm::whereKey(1)->lockForUpdate()->firstOrFail();
 $links=FormLink::whereIn('dynamic_form_version_id',$form->versions()->pluck('id'))->whereIn('status',FormLink::OPEN_STATUSES)->get(['id','dynamic_form_version_id']);
 $oldEmployeeAnswers=App\Models\FormLinkAnswer::whereIn('form_link_id',$links->pluck('id'))->where('field_key','work')->where('value','"employee"')->get(['id','form_link_id','value']);
 $backup=$dir.'/form1-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';file_put_contents($backup,json_encode(['form'=>$form->toArray(),'open_link_versions'=>$links->toArray(),'legacy_employee_answers'=>$oldEmployeeAnswers->toArray()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($backup,0600);
 if($form->schema!==$schema)$form->update(['schema'=>$schema]);
 if($form->publishedVersion?->schema!==$schema){$issues=app(App\Domain\Forms\FormPublisher::class)->publish($form,null);if($issues)throw new RuntimeException(implode('; ',$issues));}
 $published=$form->fresh()->published_version_id;
 $migrating=$links->where('dynamic_form_version_id','!=',$published)->pluck('id');
 foreach(App\Models\FormLinkAnswer::whereIn('form_link_id',$migrating)->where('field_key','work')->get()as$answer){if($answer->value==='employee')$answer->update(['value'=>'']);}
 FormLink::whereIn('id',$links->pluck('id'))->update(['dynamic_form_version_id'=>$published]);
 echo 'Published version ID '.$published.'; updated open links: '.$links->count().PHP_EOL;
});
