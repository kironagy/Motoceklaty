<?php

namespace App\Agent\Runtime;

/** Authoritative financial results need formatting, not another AI call. */
class ToolResultReply
{
    public function for(string $tool, array $result, string $text = ''): ?array
    {
        if (data_get($result,'error.code') === 'MODEL_VERSION_CLARIFICATION') return ['messages'=>[(string)data_get($result,'error.detail')]];
        if (data_get($result,'error.code') === 'MODEL_SELECTION_REQUIRED') return ['messages'=>['القسط والمصاريف بيتحسبوا حسب الموديل. تحب نحسب أنهي موتوسيكل؟']];
        if (! ($result['ok'] ?? false)) return null;
        $data = (array) ($result['data'] ?? []);
        if ($tool === 'get_branch_information') {
            $lines = [];
            foreach ((array)($data['branches'] ?? []) as $branch) {
                $line = trim(($branch['name'] ?? '').': '.($branch['address'] ?? ''));
                if (!empty($branch['map_url'])) $line .= "\n".$branch['map_url'];
                if ($line !== ':') $lines[] = $line;
            }
            return $lines !== [] ? ['messages'=>[implode("\n\n",$lines)]] : null;
        }
        if ($tool === 'search_motorcycles' && preg_match('/بكام|سعر|بكم/u', $text)
            && ! preg_match('/قسط|تقسيط|مقدم|مواصف|فرق|قارن|شحن|لون|احمر|أسود|اسود/u', $text)
            && empty($data['ambiguous_names'])) {
            $items = (array) ($data['items'] ?? []);
            if ($items === [] || count($items) > 3) return null;
            $lines = [];
            foreach ($items as $item) {
                $price = ! empty($item['is_offer']) && is_numeric($item['offer_price'] ?? null) ? $item['offer_price'] : ($item['cash_price'] ?? null);
                if (! is_numeric($price) || $price <= 0) return null;
                $lines[] = trim((string) $item['name']).' سعرها كاش '.number_format((float)$price).' جنيه.';
            }
            if (count($items) > 1) $lines[] = 'تقصد أنهي نسخة؟';
            return ['messages'=>[implode("\n", $lines)]];
        }
        if ($tool !== 'get_installment_offer') return null;
        if (! empty($data['age_blocked'])) return null;
        $offers = (array) ($data['offers'] ?? []);
        if ($offers !== []) {
            $lines = array_values(array_filter(array_map(fn ($offer) => trim((string) ($offer['line'] ?? '')), $offers)));
            if (count($lines) !== count($offers)) return null;
            if (collect($offers)->contains(fn($offer)=>(float)($offer['down_payment']??0)>0) && !empty($data['cap_reason'])) $lines[]=$data['cap_reason'];
            if (! empty($data['first_payment'])) $lines[] = $data['first_payment'];
            return ['messages'=>[implode("\n", $lines)]];
        }
        $durations = (array) ($data['durations'] ?? []);
        if ($durations === [] || collect($durations)->contains(fn ($v) => ! is_string($v))) return null;
        $minimum = (float) ($data['minimum_down_payment'] ?? 0);
        $prefix = $minimum > 0 ? 'الفرق اللي بيتدفع مقدم '.number_format($minimum).' جنيه. ' : '';
        return ['messages'=>[$prefix.'تحب القسط على '.implode(' ولا ', $durations).'؟']];
    }

    public function catalogFallback(array $outcomes): ?array
    {
        $items = [];
        foreach ($outcomes as $outcome) {
            if (empty($outcome['ok']) || !in_array($outcome['name'],['search_motorcycles','get_motorcycle_details'],true)) continue;
            foreach ((array)($outcome['data']['items'] ?? []) as $item) $items[$item['id']] = $item;
        }
        $lines = [];
        foreach (array_slice(array_values($items),0,8) as $item) {
            $price = !empty($item['is_offer']) ? ($item['offer_price'] ?? $item['new_price'] ?? $item['cash_price'] ?? null) : ($item['cash_price'] ?? null);
            if (!is_numeric($price) || $price <= 0) continue;
            $lines[] = $item['name'].': كاش '.number_format((float)$price).' جنيه.';
        }
        return $lines !== [] ? ['messages'=>[implode("\n",$lines)."\nتحب تفاصيل أنهي موديل؟"]] : null;
    }
}
