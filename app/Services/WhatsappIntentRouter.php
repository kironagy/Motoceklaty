<?php

namespace App\Services;

use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\Handlers\ApplicationHandler;
use App\Services\Handlers\MediaOcrHandler;
class WhatsappIntentRouter
{
public function handle(
    WhatsappConversation $conversation,
    string $message,
    array $mediaItems = []
): array {
    try {
        $message = trim($message);

        /*
         * المحادثة دي محوّلة لموظف دعم فعلاً - الـ AI بيسكت تمامًا ومايردش
         * لحد ما الموظف يقفل التحويل من الداشبورد (status يرجع 'open').
         * بنسجل الرسالة الجديدة بس عشان الموظف يشوفها.
         */
        if (($conversation->status ?? 'open') === 'awaiting_agent') {
            $conversation->messages()->create([
                'direction' => 'incoming',
                'message' => $message,
                'payload' => ['media_items' => $mediaItems],
            ]);

            return $this->textResult(null);
        }

        if (! count($mediaItems) && $this->isHumanSupportRequest($message)) {
            return $this->handoffToAgent($conversation, $message);
        }

        if (count($mediaItems)) {
            if (($conversation->pending_question ?? null) === 'application_documents') {
                return app(ApplicationHandler::class)->handleDocument($conversation, $mediaItems);
            }

            return app(MediaOcrHandler::class)->handle(
                conversation: $conversation,
                mediaItems: $mediaItems,
                message: $message
            );
        }

        if ($message === '' || $message === '[media]') {
            return $this->textReply($conversation, 'تحت أمرك يا فندم، تحب صور موديل معين ولا سعره؟');
        }

        $plan = app(AiIntentClassifier::class)->classify($conversation, $message);
        $intent = $plan['intent'] ?? 'unknown';

        $lastMachines = $this->lastMachinesFromConversation($conversation);
        $normalizedMessage = $this->normalizeText($message);
        $applicationIsPending = in_array(
            $conversation->pending_question ?? null,
            ['application_missing_data', 'application_documents'],
            true
        );

        $isBareConfirmationAfterCalc = $conversation->last_topic === 'installment_calc'
            && $this->isBareConfirmation($normalizedMessage);

        if (
            $applicationIsPending
            || $isBareConfirmationAfterCalc
            || (
                in_array($intent, ['general', 'unknown'], true)
                && $this->isApplicationIntent($normalizedMessage, $conversation)
            )
        ) {
            $intent = 'application';
            $plan['intent'] = 'application';
            $plan['target'] = 'single_previous_machine';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            $lastMachines->count() > 1
            && (
                $this->isVariantNarrowingReply($message)
                /*
                 * تضييق عام مش مرتبط بكلمة معينة (استيراد/فرز تاني):
                 * العميل بيكتب اسم/براند قصير بيطابق بعض (مش كل) المكن
                 * اللي عرضناها بالفعل - زي "خليك في VLR" لما نكون رشحنا
                 * أكتر من موديل وواحد منهم بس VLR. الأولوية للتضييق من
                 * نفس المرشحين قبل أي بحث جديد في الكتالوج كله.
                 */
                || $this->isGenericNarrowingReply($lastMachines, $message)
            )
        ) {
            /*
             * لو آخر رد كان فيه أكتر من موديل مطروح (زي "هوجن ٤ استيراد"
             * و"هوجن ٤ استيراد فرز تاني") والعميل رد بكلمة تحديد زي
             * "استيراد" أو "فرز تاني"، منسيبش الـ AI classifier يخمن -
             * بيميل يرجع أول موديل في القايمة القديمة بدل ما يفلتر فعليًا.
             * بنرجع كل الموديلات السابقة وبعدين بنفلترها بالكلمة نفسها.
             */
            $intent = in_array($intent, ['general', 'unknown'], true)
                ? $this->detectIntent($message)
                : $intent;
            $plan['intent'] = $intent;
            $plan['target'] = 'previous_selection';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['references_all_previous'] = true;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            in_array($intent, ['general', 'unknown'], true)
            && $lastMachines->isNotEmpty()
            && (
                $this->isPureFollowUp($message)
                /*
                 * تأكيد بسيط ("تمام"، "ماشي") بعد ما عرضنا مكنة/مكن، من غير
                 * ما يكون فيه سياق تقسيط شغال (ده متغطي فوق في
                 * isBareConfirmationAfterCalc) - يعني "استمر معايا في
                 * اللي عرضته" مش "ابدأ من الصفر واسألني عاوز مكنه ايه".
                 */
                || $this->isBareConfirmation($normalizedMessage)
            )
        ) {
            $intent = $this->detectIntent($message);
            $plan['intent'] = $intent;
            $plan['target'] = $lastMachines->count() === 1
                ? 'single_previous_machine'
                : 'previous_selection';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['references_all_previous'] = $lastMachines->count() > 1;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            in_array($intent, ['general', 'unknown'], true)
            && $this->isInstallmentSystemIntent($normalizedMessage)
        ) {
            $intent = 'installment_system';
            $plan['intent'] = $intent;
        }

        if (($plan['needs_clarification'] ?? false) === true) {
            $reply = $plan['clarification_question']
                ?: 'تمام يا فندم، تقصد أنهي موديل بالظبط؟';

            return $this->textReply($conversation, $reply);
        }

        $machines = $this->resolveMachinesFromPlan($conversation, $message, $plan);

        if (in_array($intent, ['general', 'unknown'], true) && $machines->isNotEmpty()) {
            $intent = $this->detectIntent($message);
            $plan['intent'] = $intent;
        }

        $searchMessage = $plan['machine_query'] ?: $message;

        if ($intent === 'installment_calc' && ($plan['target'] ?? null) === 'new_machine') {
            $machines = $this->filterMachinesByRequiredNumbers($machines, $searchMessage);
        }

        $isBrandOnly = app(MachineSearchService::class)->isBrandOnlyRequest($message);

        /*
         * لو الرسالة طلب براند بس (زي "عاوز مكنه دايو")، مبنعتمدش على
         * المكن اللي جت من resolveMachinesFromPlan() (ممكن تبقى فاضلة
         * من محادثة سابقة عن براند تاني تمامًا) - بنعمل بحث جديد مباشر
         * بالبراند المطلوب عشان مايحصلش خلط بين براندات مختلفة.
         */
        if ($isBrandOnly) {
            $freshBrandMachines = app(MachineSearchService::class)->search($message, 20);

            if ($freshBrandMachines->isNotEmpty()) {
                $machines = $freshBrandMachines;
            }
        }

        $brandFiltered = $this->filterMachinesByRequestedBrand($machines, $message);

        if (
            ($brandFiltered['brand_requested'] ?? false)
            && $brandFiltered['machines']->isEmpty()
            && ($brandFiltered['original_machines'] ?? collect())->isNotEmpty()
        ) {
            $matchedMachineDetails = $brandFiltered['original_machines']
                ->map(fn (Machine $machine) => $this->machineDisplayName($machine))
                ->implode(' ، ');

            $availableBrandMachines = Machine::query()
                ->with('brand')
                ->where('brand_id', $brandFiltered['brand_id'])
                ->get();

            $availableBrandList = $availableBrandMachines
                ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                ->implode("\n");

            $reply = $this->renderMemoryOrDefault(
                'رد موديل موجود في براند مختلف',
                [
                    'requested_brand' => $brandFiltered['brand_name'],
                    'matched_machine_details' => $matchedMachineDetails,
                    'available_brand_list' => $availableBrandList,
                ],
                "الموديل ده مش متوفر من {$brandFiltered['brand_name']}."
            );

            return $this->textResult($reply);
        }

        $machines = $brandFiltered['machines'];

        if ($machines->count() > 1) {
            $machines = $this->narrowMachinesByVariant($machines, $message);
        }

        if ($machines->isEmpty() && in_array($intent, ['installment_calc', 'application'], true)) {
            $last = $this->lastMachinesFromConversation($conversation);

            if ($last->isNotEmpty()) {
                $machines = $last;
            }
        }

        if ($intent === 'application') {
            if ($machines->isEmpty()) {
                return $this->textReply(
                    $conversation,
                    'تمام يا فندم، تحب تقدم على أنهي موديل؟'
                );
            }

            if ($machines->count() > 1) {
                $list = $machines
                    ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                    ->implode("\n");

                return $this->textReply(
                    $conversation,
                    "تمام يا فندم، تحب تقدم على أنهي موديل؟\n{$list}"
                );
            }

            $this->rememberMachines($conversation, $machines);

            $this->updateConversationState($conversation, 'application', 'application_missing_data', [
                'machine_ids' => $machines->pluck('id')->values()->all(),
                'application' => [
                    'machine_id' => $machines->first()->id,
                    'machine_name' => $this->machineDisplayName($machines->first()),
                ],
            ]);

            return app(ApplicationHandler::class)->handle($conversation, $message);
        }

        if ($intent === 'installment_system') {
            return $this->handleInstallmentSystem($conversation, $message);
        }

        if ($intent === 'installment_calc') {
            return $this->handleInstallmentCalc($conversation, $machines, $message, $plan);
        }

        if ($machines->isNotEmpty() && $isBrandOnly) {
            return $this->handleBrandModels($conversation, $machines, $message);
        }

        if ($intent === 'images') {
            return $this->handleImages($conversation, $machines, $message, $plan);
        }

        if ($intent === 'price' && $machines->isNotEmpty()) {
            return $this->handleCashPrice($conversation, $machines, $message);
        }

        return $this->handleAiFallback($conversation, $message, $machines);
    } catch (\Throwable $e) {
        Log::error('WhatsappIntentRouter simple error', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        if (isset($machines) && $machines instanceof Collection) {
            $this->rememberMachines($conversation, $machines);
        }

        return [
            'handled' => false,
            'type' => 'none',
            'reason' => 'router_exception',
            'error' => $e->getMessage(),
            'reply' => null,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ];
    }
}

private function handleAiFallback(
    WhatsappConversation $conversation,
    string $message,
    ?Collection $machines = null
): array {
    /*
     * مهم:
     * ده المسار الوحيد اللي كان بينسى المكنة اللي النظام لقاها بالفعل.
     * لو العميل قال "كي تي اكس" والرد راح للـ AI، لازم نفضل فاكرين
     * إن الكلام على KTX 250، وإلا أول ما يقول بعدها "عاوزها قسط"
     * المحادثة بتبقى فاضية والنظام بيسأله على اسم المكنة تاني.
     * بنحفظها قبل نداء الـ AI عشان الرد نفسه كمان يوصله بيانات
     * المكنة الحقيقية من الداتابيز بدل ما يخمنها من الميموري.
     */
    if ($machines instanceof Collection && $machines->isNotEmpty()) {
        $this->rememberMachines($conversation, $machines);
    }

    $conversation->refresh();

    $recentMessages = $conversation->messages()
        ->latest()
        ->take(15)
        ->get()
        ->reverse()
        ->map(fn ($m) => [
            'direction' => $m->direction,
            'message' => $m->message,
        ])
        ->values()
        ->all();

    $lastMachines = $this->lastMachinesFromConversation($conversation)
        ->map(fn (Machine $machine) => [
            'id' => $machine->id,
            'name' => $this->machineDisplayName($machine),
            'cash_price' => $machine->cash_price,
            'installment_price' => $machine->installment_price,
        ])
        ->values()
        ->all();

    $result = app(AiComplexReplyService::class)->reply($message, [
        'conversation_id' => $conversation->id,
        'from' => $conversation->from ?? null,
        'is_first_message' => count($recentMessages) <= 1,
        'messages' => $recentMessages,
        'recent_messages' => $recentMessages,
        'last_machines' => $lastMachines,
        'last_machine_ids' => $conversation->last_machine_ids ?? [],
        'current_message' => $message,
    ]);

    if (! ($result['ok'] ?? false)) {
        return $this->handoffToAgent($conversation, $message);
    }

    $reply = trim((string) $result['reply']);

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'ai_fallback',
        'intent' => $result['intent'] ?? null,
        'confidence' => $result['confidence'] ?? null,
        'model' => $result['model'] ?? null,
        'key_id' => $result['key_id'] ?? null,
    ]);

    return $this->textResult($reply);
}


private function detectIntent(string $message): string
{
    $m = $this->normalizeText($message);
if ($this->isInstallmentSystemIntent($m)) {
    return 'installment_system';
}

if ($this->isInstallmentCalcIntent($m)) {
    return 'installment_calc';
}
    /*
     * Intent الصور / الشكل / المعاينة
     */
    if (
        $this->containsAny($m, [
            'صوره',
            'صورة',
            'صور',
            'صورها',
            'صورهم',
            'صورتها',
            'صورتهم',

            'شكل',
            'شكلها',
            'شكلهم',
            'اشوف',
            'اشوفها',
            'اشوفهم',
            'شوف',
            'شوفها',
            'شوفهم',
            'عايز اشوف',
            'عايز اشوفها',
            'عايز اشوفهم',
            'وريني',
            'وريني شكلها',
            'وريني صورها',
            'وريلي',
            'ابعتهالي',
            'ابعتلي شكلها',
            'ابعتلي صور',
            'ابعتلي صورها',
            'هات صور',
            'هات صورها',
            'اعرض',
            'اعرضلي',
            'عرضها',
            'فرجني',
            'فرجني عليها',

            'الوان',
            'ألوان',
            'الوانها',
            'ألوانها',
            'متوفر منها الوان',
            'اللون',
        ])
        || preg_match('/\b(?:show|photo|photos|picture|pictures|image|images|color|colors)\b/i', $m)
    ) {
        return 'images';
    }

    /*
     * Intent السعر / الكاش
     */
    if (
        $this->containsAny($m, [
            'سعر',
            'السعر',
            'سعرها',
            'سعرهم',
            'اسعار',
            'أسعار',
            'اسعارها',
            'أسعارها',
            'اسعارهم',
            'أسعارهم',

            'بكام',
            'كام',
            'كام سعر',
            'كام سعرها',
            'كام سعرهم',
            'عامل كام',
            'عامله كام',
            'عاملين كام',
            'تعمل كام',
            'تمنها',
            'ثمنها',
            'ثمن',
            'تكلفه',
            'تكلفة',
            'تكلف',
            'كلفتها',
            'الكاش',
            'كاش',
            'نقدي',
            'نقدى',
            'فلوسها',
            'فلوس',
            'المبلغ',
            'عرض سعر',
            'اخر سعر',
            'آخر سعر',
            'اقل سعر',
            'أقل سعر',
        ])
        || preg_match('/\b(?:price|cash|cost|how much|offer)\b/i', $m)
    ) {
        return 'price';
    }

    /*
     * default:
     * لو العميل كتب اسم مكنة بس من غير طلب واضح،
     * نرجع سعر كاش كاختيار آمن مؤقتًا.
     */
    return 'price';
}

private function handleCashPrice(WhatsappConversation $conversation, Collection $machines, string $message): array
{
    $lines = [];

    foreach ($machines as $machine) {
        $price = $machine->cash_price
            ? number_format((float) $machine->cash_price) . ' جنيه'
            : 'السعر محتاج تأكيد';

$displayName = $this->machineDisplayName($machine);

$lines[] = [
    'machine' => $machine,
    'price' => $price,
    'line' => "- {$displayName}: {$price}",
];
    }

$machineListPrices = implode("\n", array_column($lines, 'line'));

if ($machines->count() > 1) {
    $reply = $this->renderMemoryOrDefault('رد اسعار عائلة موديل', [
        'machine_list_prices' => $machineListPrices,
        'machine_list' => $machines
            ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
            ->implode("\n"),
    ], "الموديلات اللي عندنا من نفس العيلة وأسعارها كاش:\n{$machineListPrices}");
} else {
    $first = $lines[0] ?? null;

    $default = $first
        ? $this->machineDisplayName($first['machine']) . ' سعرها كاش ' . $first['price']
        : 'السعر محتاج تأكيد يا فندم.';

    $reply = $this->renderMemoryOrDefault('رد سعر موديل واحد', [
        'machine_name' => $first ? $this->machineDisplayName($first['machine']) : '',
        'cash_price' => $first['price'] ?? '',
    ], $default);
}

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'simple_cash_price',
        'message' => $message,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);
    $this->rememberMachines($conversation, $machines);
    $this->updateConversationState($conversation, 'price', null, [
    'machine_ids' => $machines->pluck('id')->values()->all(),
]);
    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}

private function handleImages(
    WhatsappConversation $conversation,
    Collection $machines,
    string $message,
    array $plan = []
): array {
    $aiIntent = $plan;

    if (
        $machines->isEmpty()
        || (($aiIntent['target'] ?? null) === 'last_machines')
        || (($aiIntent['uses_last_machines'] ?? false) === true)
    ) {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty()) {
            $machines = $last;
        }
    }

    if ($machines->isEmpty()) {
        return $this->textReply($conversation, 'تمام يا فندم، تحب صور أنهي موديل؟');
    }

    $allImages = [];
    $imageItems = [];
    $groups = [];

    foreach ($machines as $machine) {
        $displayName = $this->machineDisplayName($machine);
        $images = $this->machineImageUrls($machine);

        $groups[] = [
            'machine_id' => $machine->id,
            'machine_name' => $machine->name,
            'display_name' => $displayName,
            'images' => $images,
        ];

        foreach ($images as $img) {
            $allImages[] = $img;

            $imageItems[] = [
                'url' => $img,
                'caption' => $displayName,
                'machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'display_name' => $displayName,
            ];
        }
    }

    $allImages = array_values(array_unique(array_filter($allImages)));

    if (! count($allImages)) {
        $reply = $machines->count() > 1
            ? 'للأسف مفيش صور متسجلة حاليًا للموديلات دي.'
            : 'للأسف مفيش صور متسجلة حاليًا لـ ' . $this->machineDisplayName($machines->first()) . '.';
    } elseif ($machines->count() > 1) {
        $reply = $this->renderMemoryOrDefault(
            'رد صور عائلة موديل',
            [
                'machine_list' => $machines
                    ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                    ->implode("\n"),
            ],
            'تمام يا فندم، بعتلك الصور وكل صورة مكتوب عليها نوعها.'
        );
    } else {
        $reply = $this->renderMemoryOrDefault(
            'رد صور موديل واحد',
            [
                'machine_name' => $this->machineDisplayName($machines->first()),
            ],
            'اتفضل يا فندم دي صور ' . $this->machineDisplayName($machines->first()) . '.'
        );
    }

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'database_structured_images',
        'message' => $message,
        'ai_intent' => $aiIntent,
        'machine_groups' => $groups,
        'images' => $allImages,
        'image_items' => $imageItems,
    ]);

    $this->rememberMachines($conversation, $machines);

    $this->updateConversationState($conversation, 'images', null, [
        'machine_ids' => $machines->pluck('id')->values()->all(),
    ]);

    return array_merge($this->textResult($reply), $this->machineMeta($machines), [
        'type' => 'images',
        'image' => $allImages[0] ?? null,
        'images' => $allImages,
        'image_items' => $imageItems,
        'image_groups' => $groups,
    ]);
}


    private function machineImageUrls(Machine $machine): array
    {
        $images = [];

        foreach ($this->structuredMachineImages($machine) as $image) {
            $images[] = $image;
        }

        if (count($images)) {
            return array_values(array_unique(array_filter($images)));
        }

        $this->addImage($images, $machine->display_image ?? null);

        foreach (['colors', 'features', 'images'] as $field) {
            if (! Schema::hasColumn('machines', $field) || empty($machine->{$field})) {
                continue;
            }

            $value = $machine->{$field};

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }

            $this->collectImagesFromValue($images, $value);
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function structuredMachineImages(Machine $machine): array
    {
$folder = $this->safeFolderName($machine->name);  
      $dir = storage_path("app/public/machines-structured/{$folder}");

        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $file->getFilename()))
            ->sortBy(fn ($file) => str_pad(pathinfo($file->getFilename(), PATHINFO_FILENAME), 10, '0', STR_PAD_LEFT))
            ->map(fn ($file) => url(Storage::url("machines-structured/{$folder}/" . $file->getFilename())))
            ->values()
            ->all();
    }

    private function safeFolderName($name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/[\/\\\\:*?"<>|]+/u', '-', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return $name ?: 'unknown-machine';
    }

    private function collectImagesFromValue(array &$images, $value): void
    {
        if (! $value) {
            return;
        }

        if (is_string($value)) {
            $this->addImage($images, $value);
            return;
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                $this->collectImagesFromValue($images, $child);
            }
        }
    }

    private function addImage(array &$images, $path): void
    {
        if (! $path || ! is_string($path)) {
            return;
        }

        $path = trim($path);

        if (! $this->isValidImagePath($path)) {
            return;
        }

        $images[] = $this->formatMachineImageUrl($path);
    }

    private function isValidImagePath(string $path): bool
    {
        $lower = mb_strtolower(trim($path));

        if (preg_match('/^#?[a-f0-9]{3,8}$/i', $lower) || preg_match('/^\d+$/', $lower)) {
            return false;
        }

        if (Str::startsWith($lower, ['http://', 'https://'])) {
            return preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $lower)
                || Str::contains($lower, ['/storage/', '/uploads/', '/images/', '/media/']);
        }

        return preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $lower)
            || Str::contains($lower, ['storage/', 'uploads/', 'images/', 'media/']);
    }

    private function formatMachineImageUrl(string $path): string
    {
        $path = trim($path);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = str_replace(['/storage/', 'storage/'], '', $path);
        $path = ltrim($path, '/');

        return url(Storage::url($path));
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        $text = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );

        $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);

        $text = str_replace(
            ['استراد', 'وارد', 'فرز ثاني', 'فرز 2', 'فرز تانى', 'تانى', 'اصلى', 'بكام'],
            ['استيراد', 'استيراد', 'فرز تاني', 'فرز تاني', 'فرز تاني', 'تاني', 'اصلي', ' كام'],
            $text
        );

        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function machineMeta(Collection $machines): array
    {
        return [
            'machine_id' => $machines->first()?->id,
            'machine_ids' => $machines->pluck('id')->values()->all(),
            'machine_name' => $machines->first()?->name,
            'machine_names' => $machines->pluck('name')->values()->all(),
        ];
    }

    private function isHumanSupportRequest(string $message): bool
    {
        $m = $this->normalizeText($message);

        return $this->containsAny($m, [
            'دعم فني',
            'الدعم الفني',
            'خدمه العملاء',
            'خدمة العملاء',
            'عايز اكلم حد',
            'عاوز اكلم حد',
            'اكلم موظف',
            'اكلم حد من عندكم',
            'كلمني موظف',
            'حد يرد عليا',
            'حد يتكلم معايا',
            'عايز حد يرد',
            'عاوز حد يرد',
            'شخص حقيقي',
            'انسان حقيقي',
            'مش عايز اتكلم مع بوت',
            'مش عاوز اتكلم مع بوت',
            'مش فاهم حاجه',
            'مش فاهمه حاجه',
        ]);
    }

    /**
     * بيحول المحادثة لموظف دعم: status = awaiting_agent يوقف رد الـ AI
     * تمامًا (شيك أول handle())، وتظهر في tab "الرسائل المنتظر الرد
     * عليها" بالداشبورد لحد ما الموظف يرد ويقفل التحويل.
     */
    private function handoffToAgent(WhatsappConversation $conversation, string $message): array
    {
        $conversation->forceFill(['status' => 'awaiting_agent'])->save();

        $reply = $this->renderMemory('رد تحويل لدعم فني')
            ?: 'تمام يا فندم، هحولك دلوقتي لموظف خدمة عملاء هيتواصل معاك في أقرب وقت.';

        $this->saveOutgoing($conversation, $reply, [
            'source' => 'human_handoff',
            'message' => $message,
        ]);

        $this->archiveChatOnWhatsapp($conversation);

        return $this->textResult($reply);
    }

    /**
     * بيحط المحادثة في أرشيف الواتساب نفسه (مش داشبورد بس) عشان أي حد
     * فاتح الواتساب مباشرة يلاقيها في الأرشيف. لو فشل، مبيوقفش التحويل -
     * الأهم إن الـ AI يسكت وconversation تتعلم في الداشبورد على أي حال.
     */
    private function archiveChatOnWhatsapp(WhatsappConversation $conversation): void
    {
        $lastIncoming = $conversation->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $conversation->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($conversation->phone ? $conversation->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post(config('services.whatsapp.worker_url') . '/chats/archive', [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to archive WhatsApp chat on handoff', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function textReply(WhatsappConversation $conversation, string $reply): array
    {
        $this->saveOutgoing($conversation, $reply, [
            'source' => 'simple_router_text',
        ]);

        return $this->textResult($reply);
    }

    private function textResult(?string $reply, array $extra = []): array
    {
        return array_merge([
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ], $extra);
    }

    private function saveOutgoing(WhatsappConversation $conversation, string $reply, array $payload = []): void
    {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => $payload,
        ]);
    }
    
private function machineDisplayName(Machine $machine): string
{
    $brand = $this->machineBrandName($machine);
    $name = trim((string) $machine->name);

    if ($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
        return $brand . ' ' . $name;
    }

    return $name;
}

private function rememberMachines(WhatsappConversation $conversation, Collection $machines): void
{
    if ($machines->isEmpty()) {
        return;
    }

    $ids = $machines
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->filter(fn (int $id) => $id > 0)
        ->unique()
        ->values()
        ->all();

    if (empty($ids)) {
        return;
    }

    $currentActiveId = (int) ($conversation->last_machine_id ?? 0);
    $activeId = count($ids) === 1
        ? $ids[0]
        : (in_array($currentActiveId, $ids, true) ? $currentActiveId : null);

    $data = [
        'last_machine_id' => $activeId,
        'last_machine_ids' => $ids,
    ];

    $allowed = [];

    foreach ($data as $key => $value) {
        if (Schema::hasColumn('whatsapp_conversations', $key)) {
            $allowed[$key] = $value;
        }
    }

    if ($allowed) {
        $conversation->forceFill($allowed)->save();
    }
}

private function lastMachinesFromConversation(WhatsappConversation $conversation): Collection
{
    $ids = Schema::hasColumn('whatsapp_conversations', 'last_machine_ids')
        ? ($conversation->last_machine_ids ?? [])
        : [];

    if (is_string($ids)) {
        $ids = json_decode($ids, true) ?: [];
    }

    if (! is_array($ids)) {
        $ids = [];
    }

    if (
        empty($ids)
        && Schema::hasColumn('whatsapp_conversations', 'last_machine_id')
        && ! empty($conversation->last_machine_id)
    ) {
        $ids = [(int) $conversation->last_machine_id];
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        fn (int $id) => $id > 0
    )));

    if (empty($ids)) {
        return collect();
    }

    return Machine::query()
        ->with('brand')
        ->whereIn('id', $ids)
        ->get()
        ->sortBy(fn ($machine) => array_search($machine->id, $ids, true))
        ->values();
}

private function activeMachineFromConversation(WhatsappConversation $conversation): ?Machine
{
    if (
        Schema::hasColumn('whatsapp_conversations', 'last_machine_id')
        && ! empty($conversation->last_machine_id)
    ) {
        $machine = Machine::query()
            ->with('brand')
            ->find((int) $conversation->last_machine_id);

        if ($machine) {
            return $machine;
        }
    }

    return $this->lastMachinesFromConversation($conversation)->first();
}

private function resolveMachinesFromPlan(
    WhatsappConversation $conversation,
    string $message,
    array $plan
): Collection {
    $target = $plan['target'] ?? 'unknown';
    $hasMachineQuery = ! empty($plan['machine_query']);

    /*
     * لو الـ AI قال "target = يرجع لموديل سابق" بس فعليًا مفيش موديل
     * سابق في المحادثة (أول رسالة، أو محادثة جديدة)، ومعانا اسم موديل
     * صريح في machine_query، منسيبش الرد يقف فاضي - نكمل على البحث
     * بالاسم بدل ما نرجع collection فاضية.
     */
    if ($target === 'previous_selection') {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty() || ! $hasMachineQuery) {
            return $last;
        }
    }

    if ($target === 'single_previous_machine') {
        $machine = $this->activeMachineFromConversation($conversation);

        if ($machine) {
            return collect([$machine]);
        }

        if (! $hasMachineQuery) {
            return collect();
        }
    }

    if ($target === 'selected_index') {
        $index = (int) ($plan['selected_index'] ?? 0);

        if ($index > 0) {
            $last = $this->lastMachinesFromConversation($conversation);
            $machine = $last->get($index - 1);

            if ($machine) {
                return collect([$machine]);
            }

            if (! $hasMachineQuery) {
                return collect();
            }
        }
    }

    if ($hasMachineQuery) {
        $query = trim((string) $plan['machine_query']);

        $found = app(MachineSearchService::class)->search($query, 20);

        if ($found->isEmpty()) {
            $found = app(MachineSearchService::class)->search($message, 20);
        }

        if ($found->isNotEmpty()) {
            return $found;
        }
    }

    if (! empty($plan['machine_ids']) && is_array($plan['machine_ids'])) {
        return Machine::query()
            ->with('brand')
            ->whereIn('id', $plan['machine_ids'])
            ->get()
            ->sortBy(fn ($machine) => array_search($machine->id, $plan['machine_ids'], true))
            ->values();
    }

    if (($plan['uses_last_machines'] ?? false) === true) {
        return $this->lastMachinesFromConversation($conversation);
    }
    
    if (($plan['intent'] ?? null) === 'installment_calc') {
    $last = $this->lastMachinesFromConversation($conversation);

    if ($last->isNotEmpty()) {
        return $last;
    }

    return collect();
}

    return app(MachineSearchService::class)->search($message, 20);
}


/**
 * لو الرسالة قصيرة (1-3 كلمات، مفيهاش أرقام كتير) وبتطابق جزء (مش كل)
 * المكن اللي اتعرضت قبل كده، اعتبرها تضييق مش طلب جديد. بنستخدم نفس
 * normalizeSearchText بتاعة MachineSearchService عشان نتجنب تكرار أي
 * أخطاء تطبيع (زي مشكلة دايو/دايون اللي اتصلحت هناك).
 */
private function isGenericNarrowingReply(Collection $lastMachines, string $message): bool
{
    $search = app(MachineSearchService::class);
    $normalized = trim($search->normalizeSearchText($message));

    if ($normalized === '' || str_word_count($normalized) > 3) {
        return false;
    }

    $matches = 0;

    foreach ($lastMachines as $machine) {
        $name = $search->normalizeSearchText(
            trim($this->machineBrandName($machine) . ' ' . $machine->name)
        );

        if ($name !== '' && str_contains($name, $normalized)) {
            $matches++;
        }
    }

    return $matches > 0 && $matches < $lastMachines->count();
}

private function isVariantNarrowingReply(string $message): bool
{
    $m = $this->normalizeText($message);

    return $this->containsAny($m, [
        'استيراد',
        'فرز تاني',
        'فرز ثاني',
        'اصلي',
        'اصليه',
        'محلي',
        'محليه',
        'cc200',
    ]);
}

private function isPureFollowUp(string $message): bool
{
    $m = $this->normalizeText($message);

    return $this->containsAny($m, [
    'قسطها',
'قسطه',
'تقسيطها',
'تقسيطه',
'القسط كام',
'قسط كام',
'شهري',
'شهريا',
'كل شهر',
        'صورها',
        'صورتها',
        'شكلها',
        'اشوفها',
        'عايز اشوفها',
        'عايز صورها',
        'هات صورها',
        'ابعت صورها',
        'وريني صورها',
        'الوانها',
        'سعرها',
        'كام سعرها',
        'بكام',
        'احسبهالي',
'احسبهالى',
'احسبها',
'احسبلي',
'احسبلى',
'عايزها على سنه',
'عايزها علي سنه',
'عايزه على سنه',
'عايزه علي سنه',
'على سنه',
'علي سنه',
'على سنة',
'علي سنة',
'سنه',
'سنة',
'سنتين',
'على سنتين',
'علي سنتين',
'18 شهر',
'24 شهر',
'36 شهر',
    ]);
}
private function handleBrandModels(WhatsappConversation $conversation, Collection $machines, string $message): array
{
    $brandName = $this->machineBrandName($machines->first()) ?: 'الشركة';

    $names = $machines
        ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
        ->implode("\n");

$reply = $this->renderMemoryOrDefault('رد اختيار موديل من براند', [
    'brand_name' => $brandName,
    'machine_list' => $names,
], "عندي من {$brandName} الموديلات دي:\n{$names}\n\nتحب أبعتهالك صور ولا سعر أنهي موديل؟");
    $this->saveOutgoing($conversation, $reply, [
        'source' => 'brand_models_list',
        'message' => $message,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);

    $this->rememberMachines($conversation, $machines);

    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}
private function machineBrandName(Machine $machine): string
{
    if (method_exists($machine, 'brand')) {
        return trim((string) ($machine->brand?->name ?? ''));
    }

    return '';
}

private function renderMemory(string $title, array $variables = []): ?string
{
    if (! class_exists(\App\Services\AiMemoryResolver::class)) {
        return null;
    }

    $memory = app(\App\Services\AiMemoryResolver::class)->memoryByExactTitle($title);

    if (! $memory) {
        return null;
    }

    $reply = app(\App\Services\AiReplyBuilder::class)
        ->fromMemories(collect([$memory]), $variables, '');

    $reply = trim((string) $reply);

    return $reply !== '' ? $reply : null;
}

private function renderMemoryOrDefault(string $title, array $variables, string $default): string
{
    return $this->renderMemory($title, $variables) ?: $default;
}


/*القسط*/
private function handleInstallmentSystem(
    WhatsappConversation $conversation,
    string $message
): array {
    $reply = $this->renderMemory('رد نظام التقسيط')
        ?: "التقسيط عندنا متاح للموظفين وأصحاب الأنشطة وأصحاب المعاشات والمهن الحرة.\n\n"
        . "عندنا نظامين:\n"
        . "- نظام 20% سنويًا، وفيه 7% مصاريف إدارية.\n"
        . "- نظام 30% سنويًا، بدون مصاريف إدارية.\n\n"
        . "المدد المتاحة: 12 أو 18 أو 24 أو 36 شهر.\n"
        . "ولو حابب أحسبلك القسط ابعتلي المدة، ولو مكنة مختلفة ابعتلي اسمها.";

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_system',
        'message' => $message,
    ]);

$this->updateConversationState($conversation, 'installment_system', null, [
    'machine_ids' => $conversation->last_machine_ids ?? [],
]);
    return $this->textResult($reply);
}


private function isInstallmentSystemIntent(string $m): bool
{
    return $this->containsAny($m, [
        'نظام التقسيط',
        'انظمه التقسيط',
        'انظمة التقسيط',
        'شروط التقسيط',
        'ورق التقسيط',
        'المستندات',
        'بتقسطوا ازاي',
        'بقسط ازاي',
        'ازاي اقسط',
        'ايه نظام القسط',
        'ايه نظام التقسيط',
        'التقسيط عندكم',
        'انظمة اية',
'انظمه ايه',
'ايه الانظمه المتاحه',
'ايه الانظمة المتاحة',
'نظام القسط ايه',
'بتقسطوا تبع ايه',
'شركات التمويل',

'ايه الانظمه المتاحه',
'ايه الانظمة المتاحة',
'الانظمه المتاحه',
'الانظمة المتاحة',
'بتقسطوا تبع ايه',
'بتقسطو تبع ايه',
'تقسيط تبع ايه',
'انظمة ايه',
'انظمه ايه',
'انظمة التقسيط ايه',
'انظمه التقسيط ايه',
'بتقسطو ازاي',
'بتقسّطوا ازاي',
'بتقسط ازاي',
'التقسيط ازاي',
'عايز اعرف التقسيط',
'عايز اعرف نظام التقسيط',
'ايه نظامكم في التقسيط',
'ايه نظامكم',
'انظمتكم ايه',
'انظمتكو ايه',
    ]);
}

private function isInstallmentCalcIntent(string $m): bool
{
    return $this->containsAny($m, [
        'قسطها',
        'قسطه',
        'القسط كام',
        'قسط كام',
        'تقسيطها كام',
        'تقسيطه كام',
        'شهري',
        'شهريا',
        'كل شهر',
        'مقدم',
        'بدون مقدم',
        'من غير مقدم',
        'على سنة',
        'علي سنه',
        'على سنتين',
        'علي سنتين',
        '12 شهر',
        '18 شهر',
        '24 شهر',
        '36 شهر',
        'احسبهالي',
'احسبهالى',
'احسبها',
'احسبلي',
'احسبلى',
'عايزها على',
'عايزها علي',
'عايزه على',
'عايزه علي',
    ]);
}



private function handleInstallmentCalc(
    WhatsappConversation $conversation,
    Collection $machines,
    string $message,
    array $plan = []
): array {
    $aiIntent = $plan;
    $parsed = app(InstallmentTextParser::class)->parse($message);
    $parsed = $this->applyAiParsedInstallment($parsed, $aiIntent);

    if (
        $machines->isEmpty()
        || (($aiIntent['target'] ?? null) === 'last_machines')
        || (($aiIntent['uses_last_machines'] ?? false) === true)
    ) {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty()) {
            $machines = $last;
        }
    }

    if ($machines->isEmpty()) {
        $this->updateConversationState($conversation, 'installment_calc', 'choose_machine');

        $reply = $this->renderMemory('رد طلب المكنة لحساب القسط')
            ?: 'تمام يا فندم، ابعتلي اسم المكنة اللي عايز أحسب قسطها.';

        return $this->textReply($conversation, $reply);
    }

    $months = $parsed['months'] ?? null;

    if (! $months) {
        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'installment_calc', 'choose_months', [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        $reply = $this->renderMemory('رد طلب مدة التقسيط')
            ?: 'تمام يا فندم، تحب التقسيط على كام شهر؟ 12 ولا 18 ولا 24 ولا 36؟';

        return $this->textReply($conversation, $reply);
    }

    $validMonths = $this->validMonthsForMachines($machines);

    if (! empty($validMonths) && ! in_array((int) $months, $validMonths, true)) {
        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'installment_calc', 'choose_months', [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        $list = implode(' / ', $validMonths);

        return $this->textReply(
            $conversation,
            "المدة دي مش متاحة يا فندم، مدد التقسيط المتاحة للمكنة دي: {$list} شهر. تحب تقسط على قد ايه؟"
        );
    }

    $deposit = (float) ($parsed['deposit'] ?? 0);
    $system = (string) ($parsed['system'] ?? '20');

    $calculations = app(InstallmentCalculator::class)
        ->calculateMany($machines, (int) $months, $deposit, $system);

    $built = app(InstallmentVariablesBuilder::class)->build($calculations);

    if (! ($built['ok'] ?? false)) {
        $reply = $this->renderMemory('رد عدم توفر سعر التقسيط')
            ?: 'القسط محتاج تأكيد يا فندم لأن سعر التقسيط مش متسجل للموديل ده.';

        return $this->textReply($conversation, $reply);
    }

    $variables = $built['variables'];

    $defaultReply =
        "تمام يا فندم، القسط على {$variables['months']} شهر {$variables['deposit_text']}:\n\n" .
        "{$variables['installment_list']}\n\n" .
        "{$variables['admin_fee_text']}";

    $reply = $this->renderMemoryOrDefault(
        'رد حساب القسط',
        $variables,
        $defaultReply
    );

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_calc_ai_state',
        'message' => $message,
        'ai_intent' => $aiIntent,
        'parsed' => $parsed,
        'calculations' => $calculations,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);

    $this->rememberMachines($conversation, $machines);
    $this->updateConversationState($conversation, 'installment_calc', null, [
        'last_months' => $months,
        'last_system' => $system,
        'last_deposit' => $deposit,
    ]);

    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}

/**
 * كل مكنة مرتبطة بـ IDs في installment_systems (array)، وكل نظام تقسيط
 * له خطط (plans) فيها months محددة. برجع union لكل المدد المتاحة فعليًا
 * عبر كل المكن الممررة، عشان العميل ميكتبش رقم شهور من دماغه.
 */
private function validMonthsForMachines(Collection $machines): array
{
    $systemIds = $machines
        ->flatMap(function (Machine $machine) {
            $ids = $machine->installment_systems ?? [];

            return is_array($ids) ? $ids : [];
        })
        ->filter()
        ->unique()
        ->values()
        ->all();

    if (empty($systemIds)) {
        return [];
    }

    return InstallmentSystem::query()
        ->whereIn('id', $systemIds)
        ->get()
        ->flatMap(fn (InstallmentSystem $system) => collect($system->plans ?? [])->pluck('months'))
        ->map(fn ($m) => (int) $m)
        ->filter(fn ($m) => $m > 0)
        ->unique()
        ->sort()
        ->values()
        ->all();
}

private function extractRequestedBrand(string $message): ?array
{
    $m = $this->normalizeText($message);

    $brands = \App\Models\Brand::all();

    foreach ($brands as $brand) {
        $name = trim((string) $brand->name);

        if ($name !== '' && str_contains($m, $this->normalizeText($name))) {
            return [
                'id' => $brand->id,
                'name' => $name,
            ];
        }
    }

    return null;
}


private function filterMachinesByRequestedBrand(
    Collection $machines,
    string $message
): array {

    $requestedBrand = $this->extractRequestedBrand($message);

    if (! $requestedBrand) {
        return [
            'brand_requested' => false,
            'machines' => $machines,
        ];
    }

    $filtered = $machines
        ->filter(fn (Machine $machine)
            => (int) $machine->brand_id === (int) $requestedBrand['id'])
        ->values();

    return [
        'brand_requested' => true,
        'brand_id' => $requestedBrand['id'],
        'brand_name' => $requestedBrand['name'],
        'machines' => $filtered,
        'original_machines' => $machines,
    ];
}
private function extractMachineSearchTextForInstallment(string $message): string
{
    $text = $this->normalizeText($message);

    $text = preg_replace('/\b(?:عايز|عاوز|محتاج|احسب|احسبلي|حساب|قسط|القسط|تقسيط|تقسيطها|قسطها|قسطه|كام|بكام)\b/u', ' ', $text);

$text = preg_replace('/\b(?:علي|على)\s*(?:سنه|سنة|سنتين|\d+\s*(?:شهر|شهور))\b/u', ' ', $text);
$text = preg_replace('/\b(?:لمده|لمدة|مدة)\s*(?:سنه|سنة|سنتين|\d+\s*(?:شهر|شهور))\b/u', ' ', $text);
$text = preg_replace('/\b\d+\s*(?:شهر|شهور)\b/u', ' ', $text);
$text = preg_replace('/\b(?:سنه|سنة|سنتين|شهر|شهور)\b/u', ' ', $text);

    $text = preg_replace('/\b(?:بمقدم|مقدم|هدفع|ادفع|دافع)\s*\d+(?:\.\d+)?\b/u', ' ', $text);
    $text = preg_replace('/\b(?:بدون مقدم|من غير مقدم|مفيش مقدم|صفر مقدم)\b/u', ' ', $text);

    $text = preg_replace('/\b(?:شهري|شهريا|كل شهر|نظام|مصاريف|اداريه|ادارية)\b/u', ' ', $text);

    $text = preg_replace('/\s+/u', ' ', $text);

    return trim($text) ?: $message;
}



private function filterMachinesByRequiredNumbers(Collection $machines, string $message): Collection
{
    preg_match_all('/\d+/u', $this->normalizeText($message), $matches);

    $numbers = array_values(array_unique($matches[0] ?? []));

    if (empty($numbers) || $machines->count() <= 1) {
        return $machines;
    }

    $filtered = $machines->filter(function (Machine $machine) use ($numbers) {
        $name = $this->normalizeText((string) $machine->name);

        foreach ($numbers as $number) {
            if (! preg_match('/(?:^|\D)' . preg_quote($number, '/') . '(?:\D|$)/u', $name)) {
                return false;
            }
        }

        return true;
    })->values();

    return $filtered->isNotEmpty() ? $filtered : $machines;
}



private function narrowMachinesByVariant(Collection $machines, string $message): Collection
{
    $m = $this->normalizeText($message);

    if ($machines->count() <= 1) {
        return $machines;
    }

    if (str_contains($m, 'فرز تاني') || str_contains($m, 'فرز ثاني')) {
        $filtered = $machines->filter(fn ($machine) =>
            str_contains($this->normalizeText($machine->name), 'فرز تاني')
            || str_contains($this->normalizeText($machine->name), 'فرز ثاني')
        )->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    if (str_contains($m, 'استيراد')) {
        $filtered = $machines->filter(function ($machine) {
            $name = $this->normalizeText($machine->name);

            return str_contains($name, 'استيراد')
                && ! str_contains($name, 'فرز تاني')
                && ! str_contains($name, 'فرز ثاني');
        })->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    $search = app(MachineSearchService::class);
    $normalized = trim($search->normalizeSearchText($message));

    if ($normalized !== '' && str_word_count($normalized) <= 3) {
        $filtered = $machines->filter(function (Machine $machine) use ($search, $normalized) {
            $name = $search->normalizeSearchText(
                trim($this->machineBrandName($machine) . ' ' . $machine->name)
            );

            return $name !== '' && str_contains($name, $normalized);
        })->values();

        if ($filtered->isNotEmpty() && $filtered->count() < $machines->count()) {
            return $filtered;
        }
    }

    return $machines;
}




private function chooseIntent(string $ruleIntent, array $aiIntent): string
{
    $ai = $aiIntent['intent'] ?? 'unknown';
    $confidence = (float) ($aiIntent['confidence'] ?? 0);

    if (in_array($ai, [
        'price',
        'images',
        'installment_calc',
        'installment_system',
        'brand_models',
    ], true) && $confidence >= 0.55) {
        return $ai;
    }

    return $ruleIntent;
}

private function applyAiParsedInstallment(array $parsed, array $aiIntent): array
{
    if (! empty($aiIntent['months'])) {
        $parsed['months'] = (int) $aiIntent['months'];
    }

    if (array_key_exists('deposit', $aiIntent) && $aiIntent['deposit'] !== null) {
        $parsed['deposit'] = (float) $aiIntent['deposit'];
        $parsed['deposit_mentioned'] = true;
    }

    if (! empty($aiIntent['system']) && in_array((string) $aiIntent['system'], ['20', '30'], true)) {
        $parsed['system'] = (string) $aiIntent['system'];
    }

    return $parsed;
}

private function updateConversationState(
    WhatsappConversation $conversation,
    ?string $topic = null,
    ?string $pendingQuestion = null,
    array $payload = []
): void {
    $currentPayload = $conversation->context_payload ?? [];

    if (is_string($currentPayload)) {
        $currentPayload = json_decode($currentPayload, true) ?: [];
    }

    if (! is_array($currentPayload)) {
        $currentPayload = [];
    }

    $mergedPayload = $this->mergeContextPayload($currentPayload, $payload);

    $conversation->forceFill([
        'last_topic' => $topic,
        'pending_question' => $pendingQuestion,
        'context_payload' => $mergedPayload ?: null,
    ])->save();
}

private function mergeContextPayload(array $current, array $updates): array
{
    foreach ($updates as $key => $value) {
        if (
            array_key_exists($key, $current)
            && is_array($current[$key])
            && is_array($value)
            && ! array_is_list($current[$key])
            && ! array_is_list($value)
        ) {
            $current[$key] = $this->mergeContextPayload($current[$key], $value);
            continue;
        }

        $current[$key] = $value;
    }

    return $current;
}






/*
 * لو العميل رد بكلمة تأكيد قصيرة ("تمام"، "اوك"، "تمام كده") فورًا بعد
 * ما حسبنا له القسط، ده يعني موافقة على الشراء مش سؤال سعر جديد.
 */
private function isBareConfirmation(string $normalizedMessage): bool
{
    $normalized = trim($normalizedMessage);

    return in_array($normalized, [
        'تمام',
        'تمام كده',
        'تمام يافندم',
        'اوك',
        'ok',
        'اه تمام',
        'ايوه تمام',
        'موافق',
        'ماشي',
        'ماشي تمام',
        'حلو',
        'تمام كدا',
        'اه',
        'ايوه',
        'ايوا',
    ], true);
}

private function isApplicationIntent(string $normalizedMessage, WhatsappConversation $conversation): bool
{
    if (($conversation->pending_question ?? null) === 'application_missing_data') {
        return true;
    }

    return str_contains($normalizedMessage, 'اقدم')
        || str_contains($normalizedMessage, 'تقديم')
        || str_contains($normalizedMessage, 'قدملي')
        || str_contains($normalizedMessage, 'اعمل طلب')
        || str_contains($normalizedMessage, 'امشي في الاجراءات')
        || str_contains($normalizedMessage, 'اكمل اجراءات')
        || str_contains($normalizedMessage, 'عايز اقدم')
        || str_contains($normalizedMessage, 'عاوز اقدم')
        || str_contains($normalizedMessage, 'اشتري')
        || str_contains($normalizedMessage, 'شراء')
        || str_contains($normalizedMessage, 'المطلوب')
        || str_contains($normalizedMessage, 'اعمل ايه');
}
}

