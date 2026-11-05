<?php

namespace App\Agent\Runtime;

/** Do not promise a down-payment calculation before an offer requires one. */
class PaymentWording
{
    public function apply(array $args, array $outcomes): array
    {
        foreach ($outcomes as $outcome) {
            if (($outcome['name'] ?? '') !== 'get_installment_offer' || empty($outcome['ok'])) continue;
            foreach ((array)($outcome['data']['offers'] ?? []) as $offer) {
                if ((float)($offer['minimum_down_payment'] ?? 0)>0 || (float)($offer['down_payment'] ?? 0)>0) return $args;
            }
        }
        foreach ((array)($args['messages'] ?? []) as $i=>$message) {
            if (!preg_match('/هحسب|نحسب|احسبلك/u',$message)) continue;
            $message=preg_replace('/بالمقدم\s*و\s*/u','بـ',$message);
            $message=preg_replace('/المقدم\s*و\s*(?:القسط\s*و\s*)?المصاريف(?: الإدارية| الاداريه)?/u','القسط والمصاريف الإدارية',$message);
            $message=preg_replace('/المقدم\s*و\s*القسط/u','القسط',$message);
            $args['messages'][$i]=$message;
        }
        return $args;
    }
}
