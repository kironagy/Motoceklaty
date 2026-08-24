<?php

namespace App\Console\Commands;

use App\Models\AiMemory;
use App\Services\GeminiClient;
use Illuminate\Console\Command;

/**
 * Plan task 3.4: fill `keywords` and `applicable_intents` on ai_memories.
 *
 * Both columns feed AiMemoryResolver's scoring (+25 per keyword hit, +30 for
 * a matching intent tag) and are empty on almost every row, so that scoring
 * has nothing to work with. They matter the day the memory base outgrows the
 * prompt budget and AiMemoryContextBuilder goes back to ranking instead of
 * sending everything (task 1.1).
 *
 * Intents are derived deterministically - a memory is tagged with an intent
 * when its own text contains that intent's vocabulary, which is the same
 * signal the resolver boosts on. Keywords are the part a machine cannot
 * derive from the text (they exist to catch words that are NOT in it -
 * dialect and misspellings), so those come from the model, on the cheap
 * model by default so the reasoning quota is left alone.
 *
 *   php artisan ai:memory-tags            # dry run, prints the proposal
 *   php artisan ai:memory-tags --apply    # writes, empty fields only
 *   php artisan ai:memory-tags --apply --no-ai   # intents only, no Gemini
 */
class AiMemoryTags extends Command
{
    protected $signature = 'ai:memory-tags
        {--apply : Write the proposals (only into fields that are still empty)}
        {--no-ai : Skip the Gemini call and propose intents only}
        {--delay=2 : Seconds between Gemini calls}
        {--limit=0 : Only process the first N memories}';

    protected $description = 'Propose keywords and applicable_intents for ai_memories (plan task 3.4)';

    /**
     * The vocabulary that marks a memory as relevant to an intent. Mirrors
     * AiMemoryResolver::intentKeywords(), which is what actually scores.
     */
    private const INTENT_VOCABULARY = [
        'price' => ['سعر', 'اسعار', 'أسعار', 'تسعير', 'كاش'],
        'images' => ['صور', 'صوره', 'شكل', 'الوان', 'ألوان'],
        'installment_calc' => ['قسط', 'تقسيط', 'مقدم', 'شهور', 'دفعة'],
        'installment_system' => ['نظام التقسيط', 'انظمه', 'أنظمة', 'شروط', 'مصاريف اداريه', 'مصاريف إدارية'],
        'brand_models' => ['موديل', 'موديلات', 'المخزون', 'متاح', 'براند', 'ماركة'],
        'application' => ['مستندات', 'أوراق', 'اوراق', 'بطاقة', 'تقديم', 'رخصة', 'كشف حساب', 'مفردات مرتب'],
        'application_status' => ['حالة الطلب', 'متابعة العميل', 'مراجعة البيانات'],
        'delivery_question' => ['توصيل', 'شحن', 'دليفري'],
        'complaint' => ['شكوى', 'شكاوى', 'غضب'],
        'small_talk' => ['ترحيب', 'تحية', 'أسلوب'],
    ];

    public function handle(): int
    {
        $memories = AiMemory::query()->where('is_active', true)->orderBy('id')->get();

        if (($limit = (int) $this->option('limit')) > 0) {
            $memories = $memories->take($limit);
        }

        $apply = (bool) $this->option('apply');
        $useAi = ! $this->option('no-ai');
        $delay = max(0, (int) $this->option('delay'));
        $changed = 0;

        foreach ($memories as $i => $memory) {
            $updates = [];

            if (empty($memory->applicable_intents)) {
                $intents = $this->intentsFor($memory);

                if (! empty($intents)) {
                    $updates['applicable_intents'] = $intents;
                }
            }

            if (empty($memory->keywords) && $useAi) {
                if ($i > 0 && $delay > 0) {
                    sleep($delay);
                }

                $keywords = $this->suggestKeywords($memory);

                if (! empty($keywords)) {
                    $updates['keywords'] = $keywords;
                }
            }

            if (empty($updates)) {
                continue;
            }

            $changed++;

            $this->line("#{$memory->id} {$memory->title}");

            foreach ($updates as $field => $value) {
                $this->line('   ' . $field . ': ' . implode(', ', $value));
            }

            if ($apply) {
                $memory->forceFill($updates)->save();
            }
        }

        $this->newLine();
        $this->info(($apply ? 'Updated ' : 'Would update ') . $changed . ' of ' . $memories->count() . ' memories.');

        if (! $apply) {
            $this->comment('Dry run - re-run with --apply to write. Review the result in Filament afterwards.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function intentsFor(AiMemory $memory): array
    {
        $title = $this->normalize((string) $memory->title);
        $content = $this->normalize((string) $memory->content);
        $intents = [];

        /*
         * applicable_intents is a FILTER in AiMemoryResolver::candidateMemories()
         * - a wrong tag hides the memory from the intent it actually belongs
         * to, which is worse than no tag at all. So one incidental word in a
         * long body is not enough: either the title says it, or the body says
         * it at least twice in different words.
         */
        foreach (self::INTENT_VOCABULARY as $intent => $vocabulary) {
            $titleHit = false;
            $contentHits = 0;

            foreach ($vocabulary as $word) {
                $word = $this->normalize($word);

                if ($word !== '' && str_contains($title, $word)) {
                    $titleHit = true;
                }

                if ($word !== '' && str_contains($content, $word)) {
                    $contentHits++;
                }
            }

            if ($titleHit || $contentHits >= 2) {
                $intents[] = $intent;
            }
        }

        /*
         * A memory that matches nearly everything is not a filter - leaving
         * it untagged means "usable with any intent", which is what the
         * resolver already assumes and is more honest than five tags.
         */
        return count($intents) > 4 ? [] : $intents;
    }

    /**
     * @return array<int, string>
     */
    private function suggestKeywords(AiMemory $memory): array
    {
        $content = mb_substr((string) $memory->content, 0, 1500);

        $prompt = <<<PROMPT
دي قاعدة معرفة عند معرض ماكينات في مصر، والعملاء بيسألوا عنها على واتساب بالعامية.

العنوان: {$memory->title}
المحتوى:
{$content}

اكتب من ٤ لـ ٨ كلمات أو تعبيرات **مش موجودة في النص فوق** لكن العميل ممكن يستخدمها لما يسأل عن الموضوع ده - عامية مصرية، أخطاء إملائية شائعة، ومرادفات.

قواعد: كل كلمة سطر لوحدها. من غير ترقيم ولا شرح ولا علامات. الكلمة من ١ لـ ٣ كلمات بحد أقصى.
PROMPT;

        $result = app(GeminiClient::class)->generateText($prompt, config('gemini.models.fast'), [
            'temperature' => 0.4,
            'maxOutputTokens' => 300,
            'thinkingBudget' => 0,
            'timeout' => 15,
        ]);

        if (! ($result['ok'] ?? false)) {
            $this->warn("   (no keyword suggestion for #{$memory->id}: " . ($result['error'] ?? 'call failed') . ')');

            return [];
        }

        $lines = preg_split('/\R/u', (string) ($result['reply'] ?? '')) ?: [];

        $keywords = collect($lines)
            ->map(fn ($line) => trim(preg_replace('/^[\d\-\*\.\s]+/u', '', (string) $line)))
            ->filter(fn ($line) => $line !== '' && mb_strlen($line) >= 3 && mb_strlen($line) <= 30)
            ->filter(fn ($line) => count(preg_split('/\s+/u', $line) ?: []) <= 3)
            ->unique()
            ->take(8)
            ->values()
            ->all();

        return $keywords;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
