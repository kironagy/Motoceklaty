<?php

namespace App\Services\Handlers;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class MachineImageRecognitionHandler
{
    public function handle(WhatsappConversation $conversation, array $mediaItems, string $message = ''): array
    {
        $imageItem = collect($mediaItems)->first(function ($item) {
            $mime = strtolower((string) (is_array($item) ? ($item['mime'] ?? '') : ''));

            return str_starts_with($mime, 'image/');
        });

        if (! $imageItem) {
            return $this->reply(
                $conversation,
                'تمام يا فندم، استلمت الملف. تقدر تكتبلي اسم الموديل عشان أقولك تفاصيله؟'
            );
        }

        $path = trim((string) ($imageItem['path'] ?? ''));
        $disk = Storage::disk('public');

        if ($path === '' || ! $disk->exists($path)) {
            return $this->reply($conversation, 'معلش يا فندم، مقدرش أفتح الصورة، ممكن تبعتها تاني؟');
        }

        $catalog = Machine::query()->with('brand')->orderBy('id')->get();

        $classification = $this->classifyAgainstCatalog(
            $disk->path($path),
            (string) ($imageItem['mime'] ?? 'image/jpeg'),
            $catalog
        );

        if ($classification === null) {
            return $this->reply(
                $conversation,
                'مقدرش أتعرف على المكنة من الصورة دلوقتي، تقدر تكتبلي اسم الموديل أو البراند؟'
            );
        }

        $matchId = $classification['match_id'];
        $confidence = $classification['confidence'];
        $machine = $matchId ? $catalog->firstWhere('id', $matchId) : null;

        /*
         * عمدًا مبنستخدمش بحث نصي/تخميني هنا - لو Gemini مش متأكد بصريًا
         * (confidence != high) أو مش لاقي رقم من القائمة الحقيقية، بنقول
         * صراحةً إننا مش متأكدين بدل ما نلفّق موديل غلط من فئة تانية.
         */
        if ($machine && $confidence === 'high') {
            $this->rememberMachines($conversation, collect([$machine]));

            $price = $machine->cash_price
                ? number_format((float) $machine->cash_price) . ' جنيه'
                : 'السعر محتاج تأكيد';

            $reply = "المكنة اللي في الصورة هي {$this->machineDisplayName($machine)}.\nسعرها كاش {$price}";

            return $this->reply($conversation, $reply, [
                'matched_machine_id' => $machine->id,
                'vision_classification' => $classification,
            ]);
        }

        $visibleText = trim((string) ($classification['visible_text'] ?? ''));
        $hint = $visibleText !== '' ? " شفت إن فيها كتابة \"{$visibleText}\"،" : '';

        return $this->reply(
            $conversation,
            "مش متأكد بالظبط من الموديل من الصورة دي،{$hint} ممكن تأكدلي اسم الموديل أو البراند عشان أقولك تفاصيله بالظبط؟",
            ['vision_classification' => $classification]
        );
    }

    /**
     * بنبعت لـ Gemini قائمة المكن الحقيقية اللي عندنا في الكتالوج (مش
     * كتير - العدد صغير) ونخليه يختار id منها لو متأكد بصريًا، بدل ما
     * يخترع اسم موديل من عنده ونحاول نلاقيه بالبحث النصي (ده كان بيرجع
     * موديلات من فئة تانية خالص زي سكوتر بدل موتوسيكل).
     */
    private function classifyAgainstCatalog(string $absolutePath, string $mime, Collection $catalog): ?array
    {
        $binary = @file_get_contents($absolutePath);

        if ($binary === false || $catalog->isEmpty()) {
            return null;
        }

        $list = $catalog
            ->map(fn (Machine $machine) => $machine->id . ') ' . $this->machineDisplayName($machine))
            ->implode("\n");

        $prompt = <<<PROMPT
انت خبير بيتعرف على مكن/موتوسيكلات وسكوترات من صورة، وبتقارنها بقائمة الموديلات الحقيقية المتاحة عندنا بس تحت. اختار رقم (id) الموديل الأقرب للي في الصورة *بس لو متأكد بصريًا* (الشكل والتصميم والشعار متطابقين فعلاً)، ولو مش متأكد أو الصورة مش واضحة كفاية أو الموديل مش موجود في القائمة، رجع match_id: null - ممنوع تخمّن أو تختار أقرب حاجة شكلها شبه لو مش متأكد فعلاً، خصوصًا إن سكوتر وموتوسيكل حاجتين مختلفتين تمامًا حتى لو نفس البراند.

قائمة الموديلات (id) اسم:
{$list}

رد بصيغة JSON فقط بدون أي كلام زيادة، بالشكل ده بالظبط:
{"match_id": رقم من القايمة فوق أو null, "confidence": "high أو medium أو low", "visible_text": "أي نص أو شعار ظاهر فعليًا في الصورة"}
PROMPT;

        $result = app(GeminiClient::class)->generateText($prompt, 'gemini-3.1-flash-lite', [
            'image_base64' => base64_encode($binary),
            'image_mime' => $mime,
            'temperature' => 0.1,
            'maxOutputTokens' => 200,
            'responseMimeType' => 'application/json',
        ]);

        if (! ($result['ok'] ?? false)) {
            Log::warning('machine image recognition gemini failed', ['error' => $result['error'] ?? null]);

            return null;
        }

        $decoded = json_decode((string) ($result['reply'] ?? ''), true);

        if (! is_array($decoded)) {
            return null;
        }

        $matchId = $decoded['match_id'] ?? null;
        $matchId = is_numeric($matchId) ? (int) $matchId : null;

        if ($matchId !== null && ! $catalog->contains('id', $matchId)) {
            $matchId = null;
        }

        return [
            'match_id' => $matchId,
            'confidence' => in_array($decoded['confidence'] ?? null, ['high', 'medium', 'low'], true)
                ? $decoded['confidence']
                : 'low',
            'visible_text' => trim((string) ($decoded['visible_text'] ?? '')),
        ];
    }

    private function machineDisplayName(Machine $machine): string
    {
        $brand = trim((string) ($machine->brand?->name ?? ''));
        $name = trim((string) $machine->name);

        if ($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
            return $brand . ' ' . $name;
        }

        return $name;
    }

    private function rememberMachines(WhatsappConversation $conversation, Collection $machines): void
    {
        $ids = $machines->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        if (empty($ids)) {
            return;
        }

        $data = [
            'last_machine_id' => count($ids) === 1 ? $ids[0] : null,
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

    private function reply(WhatsappConversation $conversation, string $reply, array $extra = []): array
    {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => array_merge(['source' => 'machine_image_recognition'], $extra),
        ]);

        return [
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
            'intent' => 'machine_image_recognition',
            'source' => 'machine_image_recognition',
        ];
    }
}
