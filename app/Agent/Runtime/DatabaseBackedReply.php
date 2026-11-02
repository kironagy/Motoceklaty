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

        if (preg_match('/بين|قارن|مقارنه|مقارنة/u', $normalized)) {
            $ids = app(CatalogMentions::class)->fullNames($text);
            if (count($ids) >= 2) {
                $args = ['motorcycle_ids'=>array_slice(array_unique($ids), 0, 3)];
                $result = $this->tools->execute('get_motorcycle_details', $args, $ctx);
                if (! ($result['ok'] ?? false)) return null;
                $lines = [];
                foreach (\App\Models\Machine::with('brand')->whereIn('id', $ids)->where('is_active', true)->get() as $machine) {
                    $price = $machine->is_offer && is_numeric($machine->offer_price) && (float) $machine->offer_price > 0 ? $machine->offer_price : $machine->cash_price;
                    $lines[] = $this->modelLabel(['brand'=>$machine->brand?->name, 'name'=>$machine->name]).': كاش '.number_format((float) $price).' جنيه'.($machine->cc ? '، '.$machine->cc.' سي سي' : '').'.';
                }
                return ['messages'=>[implode("\n", $lines)], 'tool'=>'get_motorcycle_details', 'args'=>$args, 'result'=>$result];
            }
            return null;
        }

        if (preg_match('/(?:كام|ايه|اي) (?:ال)?فرق|(?:ال)?فرق كام/u', $normalized)) {
            $reply = $this->installmentDifference($ctx);
            if ($reply !== null) return $reply;
        }

        if (($months = $this->durationMonths($normalized)) !== null
            && (preg_match('/(?:قسط|تقسيط)/u', $normalized) || preg_match('/^(?:علي|على)?\s*(?:سنتين|سنه|سنة|3 سنين|٣ سنين|24 شهر|٢٤ شهر)/u', $normalized))) {
            $reply = $this->installmentForDuration($text, $months, $ctx);
            if ($reply !== null) return $reply;
        }

        // The most common recommendation request is a catalog query, not a
        // reasoning task. Answering it here avoids the model -> tool -> model
        // loop (and its duplicated prompt) while keeping prices authoritative.
        if (preg_match('/(?:رشح|ترشح|اقتراح|انسب|مناسب|عايز|محتاج).*(?:شغل|دليفري|اقتصاد|اقتصادي)|(?:شغل|دليفري).*(?:مكن[هة]|موتوسيكل|اقتصاد|اقتصادي)/u', $normalized)
            && ! preg_match('/(?:سكوتر|تروسيكل|ورق|اوراق|مستند|مطلوب|بطاق|شروط|طلب|مقدم|فايد|فائد)/u', $normalized)) {
            $reply = $this->economicWorkRecommendation($ctx);
            if ($reply !== null) return $reply;
        }

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
        if ($ids === []) return null;

        $name = 'search_motorcycles';
        $machine = \App\Models\Machine::query()->whereIn('id', $ids)->where('is_active', true)->with('brand')->first();
        if (! $machine) return null;
        $args = ['name_query' => (string) $machine->name, 'limit' => 8];
        $result = $this->tools->execute($name, $args, $ctx);
        if (! ($result['ok'] ?? false)) return null;
        if (! empty($result['data']['ambiguous_names'])) return null;
        $items = (array) ($result['data']['items'] ?? []);

        // The same public model name can have several catalog versions
        // (e.g. regular/imported). Listing their authoritative cash prices is
        // deterministic and should not spend three AI calls just to format it.
        if (! empty($result['data']['versions']) && count($items) > 1) {
            $lines = [];

            foreach ($items as $item) {
                $price = ! empty($item['is_offer']) && is_numeric($item['offer_price'] ?? null)
                    ? $item['offer_price'] : ($item['cash_price'] ?? null);
                if (! is_numeric($price) || (float) $price <= 0) continue;
                $label = $this->modelLabel($item);
                $lines[] = "{$label} كاش ".number_format((float) $price).' جنيه.';
            }

            if ($lines !== []) {
                $lines[] = 'تحب تفاصيل أنهي واحدة؟';

                return ['messages' => [implode("\n", $lines)], 'tool' => $name, 'args' => $args, 'result' => $result];
            }
        }

        // Ambiguous catalog rows or fuzzy matches must be clarified by the agent.
        $item = collect($items)->first(fn ($row) => (int) ($row['id'] ?? 0) === (int) $machine->id);
        if (! $item || count(array_filter($items, fn ($row) => (int) ($row['id'] ?? 0) === (int) $machine->id)) !== 1) return null;

        $price = ! empty($item['is_offer']) && is_numeric($item['offer_price'] ?? null)
            ? $item['offer_price'] : ($item['cash_price'] ?? null);
        if (! is_numeric($price) || (float) $price <= 0) return null;

        $model = $this->modelLabel($item);
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

    private function economicWorkRecommendation(ToolContext $ctx): ?array
    {
        $name = 'search_motorcycles';
        $args = ['kind' => 'motorcycle', 'sort' => 'cheapest', 'limit' => 3, 'available_only' => true];
        $result = $this->tools->execute($name, $args, $ctx);
        if (! ($result['ok'] ?? false)) return null;

        $items = collect((array) data_get($result, 'data.items', []))
            ->filter(fn ($item) => is_numeric($item['cash_price'] ?? null) && (float) $item['cash_price'] > 0)
            ->take(3)
            ->values();
        if ($items->isEmpty()) return null;

        $names = $items->map(fn ($item) => $this->modelLabel($item))->implode('، ');
        $lowest = $items->map(fn ($item) => (float) $item['cash_price'])->min();
        $message = 'للشغل والاقتصاد أرشحلك '.$names.'. الكاش بيبدأ من '.number_format($lowest).' جنيه. تحب أحسب قسط أنهي واحدة؟';

        return ['messages' => [$message], 'tool' => $name, 'args' => $args, 'result' => $result];
    }

    private function modelLabel(array $item): string
    {
        $brand = trim((string) ($item['brand'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));

        if ($brand === '' || str_starts_with(ArabicTextNormalizer::normalize($name), ArabicTextNormalizer::normalize($brand))) {
            return $name;
        }

        return trim($brand.' '.$name);
    }

    private function installmentDifference(ToolContext $ctx): ?array
    {
        $motorcycleId = $this->lastInstallmentMotorcycleId($ctx->conversationId);
        if ($motorcycleId === null) return null;

        $name = 'get_installment_offer';
        $args = ['motorcycle_id' => $motorcycleId, 'all_durations' => true];
        $result = $this->tools->execute($name, $args, $ctx);
        if (! ($result['ok'] ?? false)) return null;
        $offers = (array) data_get($result, 'data.offers', []);
        if ($offers === []) return null;

        $differences = collect($offers)->pluck('minimum_down_payment')->filter(fn ($v) => is_numeric($v) && (float) $v > 0)->map(fn ($v) => (int) round($v))->unique()->values();
        if ($differences->isEmpty()) return null;

        if ($differences->count() === 1) {
            $message = 'الفرق اللي بيتدفع مقدم '.number_format($differences->first()).' جنيه. تحب أحسبهالك على سنة، سنة ونص، سنتين ولا ٣ سنين؟';
        } else {
            $lines = collect($offers)->map(fn ($offer) => $this->durationLabel((int) $offer['months']).': الفرق '.number_format((float) $offer['minimum_down_payment']).' جنيه')->unique()->values()->all();
            $message = implode("\n", $lines);
        }

        return ['messages' => [$message], 'tool' => $name, 'args' => $args, 'result' => $result];
    }

    private function installmentForDuration(string $text, int $months, ToolContext $ctx): ?array
    {
        $ids = app(CatalogMentions::class)->fullNames($text);
        $motorcycleId = count($ids) === 1 ? (int) $ids[0] : $this->lastInstallmentMotorcycleId($ctx->conversationId);
        if ($motorcycleId === null) return null;

        $name = 'get_installment_offer';
        $args = ['motorcycle_id' => $motorcycleId, 'months' => $months];
        $result = $this->tools->execute($name, $args, $ctx);
        if (! ($result['ok'] ?? false)) return null;
        $offer = data_get($result, 'data.offers.0');
        if (! is_array($offer) || blank($offer['line'] ?? null)) return null;

        $message = trim((string) $offer['line']);
        if (filled($first = data_get($result, 'data.first_payment'))) $message .= '. '.$first;

        return ['messages' => [$message], 'tool' => $name, 'args' => $args, 'result' => $result];
    }

    private function lastInstallmentMotorcycleId(int $conversationId): ?int
    {
        $step = \App\Models\AiTraceStep::query()
            ->where('tool_name', 'get_installment_offer')
            ->whereHas('trace', fn ($query) => $query->where('conversation_id', $conversationId))
            ->latest('id')
            ->first();
        $id = data_get($step?->args_redacted, 'motorcycle_id');
        if (! is_numeric($id)) {
            $image = \App\Models\AiTraceStep::query()->where('tool_name', 'identify_motorcycle_from_image')
                ->whereHas('trace', fn ($query) => $query->where('conversation_id', $conversationId))->latest('id')->first();
            $id = data_get($image?->result_redacted, 'data.candidates.0.motorcycle_id');
        }

        return is_numeric($id) ? (int) $id : null;
    }

    private function durationMonths(string $text): ?int
    {
        return match (true) {
            preg_match('/(?:3|٣) سنين|ثلاث سنين/u', $text) === 1 => 36,
            preg_match('/سنتين|24 شهر|٢٤ شهر/u', $text) === 1 => 24,
            preg_match('/سنه ونص|سنة ونص|18 شهر|١٨ شهر/u', $text) === 1 => 18,
            preg_match('/سنه|سنة|12 شهر|١٢ شهر/u', $text) === 1 => 12,
            default => null,
        };
    }

    private function durationLabel(int $months): string
    {
        return match ($months) { 12 => 'سنة', 18 => 'سنة ونص', 24 => 'سنتين', 36 => '٣ سنين', default => $months.' شهر' };
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
