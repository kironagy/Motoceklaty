<?php

namespace App\Agent\Runtime;

/** When the model ran out of calls after a catalog lookup: what the lookup found, rather than a generic failure. */
class ToolResultReply
{
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
