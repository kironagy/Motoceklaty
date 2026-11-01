<?php

namespace App\Agent\Runtime;

use App\Agent\Tools\ToolContext;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Facades\Cache;

/** Answers narrow, database-backed facts without spending an AI call. */
class DatabaseBackedReply
{
    public function __construct(private readonly \App\Agent\Tools\ToolRegistry $tools) {}

    /** @return array{messages: string[], tool: string, args: array, result: array}|null */
    public function for(string $text, ToolContext $ctx): ?array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', ArabicTextNormalizer::normalize($text)));

        if (preg_match('/(?:عنوان|عناوين|الفروع|فروعنا|مكانكم|مواعيد الفروع|مواعيد المعرض)/u', $normalized)) {
            $name = 'get_branch_information';
            $args = [];
            $result = $this->tools->execute($name, $args, $ctx);
            if (! ($result['ok'] ?? false)) return null;
            $branches = (array) ($result['data']['branches'] ?? []);
            if ($branches === []) return null;
            $messages = $this->branchMessages($branches);
            return $messages === [] ? null : ['messages' => $messages, 'tool' => $name, 'args' => $args, 'result' => $result];
        }

        // Only a plain cash-price question. Installments, stock, availability,
        // color, comparisons and buying intent continue through the full agent.
        if (! preg_match('/(?:سعر|بكام|بكم|عامل\s+كام)/u', $normalized)
            || preg_match('/(?:قسط|تقسيط|مقدم|متاح|موجود|لون|فرع|اشتري|احجز|قارن|الفرق)/u', $normalized)) return null;

        $ids = app(CatalogMentions::class)->fullNames($text);
        if (count($ids) !== 1) return null;

        $name = 'search_motorcycles';
        $machine = \App\Models\Machine::query()->whereKey($ids[0])->where('is_active', true)->with('brand')->first();
        if (! $machine) return null;
        $args = ['name_query' => (string) $machine->name, 'limit' => 8];
        $result = $this->tools->execute($name, $args, $ctx);
        if (! ($result['ok'] ?? false)) return null;
        if (! empty($result['data']['ambiguous_names']) || ! empty($result['data']['versions'])) return null;
        $items = (array) ($result['data']['items'] ?? []);
        // Ambiguous catalog rows or fuzzy matches must be clarified by the agent.
        $item = collect($items)->first(fn ($row) => (int) ($row['id'] ?? 0) === (int) $machine->id);
        if (! $item || count(array_filter($items, fn ($row) => (int) ($row['id'] ?? 0) === (int) $machine->id)) !== 1) return null;

        $price = ! empty($item['is_offer']) && is_numeric($item['offer_price'] ?? null)
            ? $item['offer_price'] : ($item['cash_price'] ?? null);
        if (! is_numeric($price) || (float) $price <= 0) return null;

        $model = trim(($item['brand'] ?? '').' '.($item['name'] ?? ''));
        $templates = [
            "{$model} سعرها كاش ".number_format((float) $price).' جنيه. تحب أقولك تفاصيل تانية؟',
            "سعر {$model} الكاش ".number_format((float) $price).' جنيه، تحب تعرف عنها إيه كمان؟',
            "الكاش بتاع {$model} بـ ".number_format((float) $price).' جنيه. تحب أحسبلك التقسيط؟',
            "{$model} بـ ".number_format((float) $price).' جنيه كاش. لو حابب أشرحلك التقسيط قولّي.',
        ];
        $reply = $this->varied('price:'.$ctx->conversationId.':'.$machine->id, $templates);

        return ['messages' => [$reply], 'tool' => $name, 'args' => $args, 'result' => $result];
    }

    private function branchMessages(array $branches): array
    {
        $lines = [];
        foreach ($branches as $branch) {
            $line = trim((string) ($branch['name'] ?? ''));
            if (! empty($branch['address'])) $line .= ': '.$branch['address'];
            if (! empty($branch['working_hours'])) {
                $hours = $branch['working_hours'];
                $hours = is_array($hours) ? implode('، ', array_map(fn ($k, $v) => is_string($v) ? "$k: $v" : json_encode($v, JSON_UNESCAPED_UNICODE), array_keys($hours), $hours)) : (string) $hours;
                if ($hours !== '') $line .= "\nالمواعيد: {$hours}";
            }
            if (! empty($branch['map_url'])) $line .= "\nاللوكيشن: {$branch['map_url']}";
            if ($line !== '') $lines[] = $line;
        }
        if ($lines === []) return [];
        $openings = ['دي فروعنا ومواعيدها، اختار الأقرب ليك:', 'عندنا الفروع دي، شوف أنهي واحد أقربلك:', 'أهو عنوان ومواعيد كل فروعنا:'];
        return [$this->varied('branches:all', $openings)."\n\n".implode("\n\n", $lines)];
    }

    private function varied(string $key, array $options): string
    {
        $previous = Cache::get('db_reply.last.'.hash('sha256', $key));
        $choices = array_values(array_filter($options, fn ($i) => $i !== $previous));
        $index = array_rand($choices ?: $options);
        $chosen = ($choices ?: $options)[$index];
        Cache::put('db_reply.last.'.hash('sha256', $key), array_search($chosen, $options, true), now()->addHours(12));
        return $chosen;
    }
}
