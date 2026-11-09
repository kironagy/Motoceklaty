<?php
// Adds "is this number registered in the applicant's name?" right after the applicant's phone on the
// bot's form, publishes it, and moves open links to the new version (their answers are kept).
// Safe to run twice: a form that already has a phone ownership question is left alone.
if (($argv[1] ?? '') !== '--apply') exit("Usage: php scripts/deploy-phone-owner-check.php --apply\n");
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Forms\FormPublisher;
use App\Models\{DynamicForm, FormLink};
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $form = DynamicForm::where('is_bot_default', true)->lockForUpdate()->firstOrFail();
    $schema = $form->schema;

    if ($form->publishedVersion && $form->publishedVersion->schema != $schema) {
        throw new RuntimeException("Form {$form->id} has unpublished edits in the builder - publish or discard them first.");
    }

    $data = fn (array $item) => isset($item['data']) && is_array($item['data']) ? $item['data'] : $item;
    $phoneKey = null;

    foreach ($schema['sections'] as $s => $section) {
        foreach ($section['items'] as $i => $item) {
            if (($item['type'] ?? null) === 'yes_no' && filled($data($item)['confirm_phone'] ?? null)) {
                echo "Form {$form->id} already asks about the phone owner - nothing to do.".PHP_EOL;

                return;
            }
            if (($data($item)['binding'] ?? null) === 'applicant.phone') {
                [$at, $phoneKey] = [[$s, $i], $data($item)['key']];
            }
        }
    }

    if (! $phoneKey) {
        throw new RuntimeException("Form {$form->id} has no field bound to applicant.phone.");
    }

    $dir = storage_path('app/private/policy-backups');
    if (! is_dir($dir)) mkdir($dir, 0700, true);
    $backup = $dir.'/phone-owner-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($backup, json_encode(['form' => $form->toArray()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod($backup, 0600);

    array_splice($schema['sections'][$at[0]]['items'], $at[1] + 1, 0, [
        ['type' => 'yes_no', 'data' => ['key' => 'phone_owner', 'label' => 'الرقم ده متسجّل باسم صاحب الطلب في شركة الاتصالات؟', 'required' => true, 'confirm_phone' => $phoneKey,
            'help' => 'لازم الخط يكون متسجّل باسم صاحب الطلب في شركته (فودافون / أورنج / اتصالات / WE).',
            'conditions' => [['field' => $phoneKey, 'operator' => 'filled']]]],
        ['type' => 'stop', 'data' => ['key' => 'phone_not_owner', 'label' => 'الرقم مش باسم صاحب الطلب',
            'message' => 'لازم تكتب رقم موبايل متسجّل باسم صاحب الطلب، لأن ده من شروط قبول الطلب. دوس "تعديل الرقم" واكتب الرقم الصح.',
            'conditions' => [['field' => 'phone_owner', 'operator' => 'equals', 'value' => 'no']]]],
    ]);

    $links = FormLink::whereIn('dynamic_form_version_id', $form->versions()->where('version', '>', 0)->pluck('id'))
        ->whereIn('status', FormLink::OPEN_STATUSES)->pluck('id');

    $form->update(['schema' => $schema]);
    if ($issues = app(FormPublisher::class)->publish($form, null)) throw new RuntimeException(implode('; ', $issues));
    $published = $form->fresh()->published_version_id;
    FormLink::whereIn('id', $links)->update(['dynamic_form_version_id' => $published]);

    echo "Form {$form->id}: published version ID {$published}; open links moved: {$links->count()}; backup {$backup}".PHP_EOL;
});
