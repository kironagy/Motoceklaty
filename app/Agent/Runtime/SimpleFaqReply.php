<?php

namespace App\Agent\Runtime;

use App\Support\ArabicTextNormalizer;

/** Approved, context-free FAQs that do not need a model or database tool. */
class SimpleFaqReply
{
    public function for(string $text): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', ArabicTextNormalizer::normalize($text)));

        if ($text === '' || mb_strlen($text) > 180) {
            return null;
        }

        $asksFinanceCompany = preg_match('/(?:مع مين|شركة ايه|شركه ايه|جهه ايه|جهة ايه|مين بيمول|مين بيمول|شركة التمويل|شركه التمويل)/u', $text)
            && preg_match('/(?:قسط|تقسيط|تمويل)/u', $text);
        $asksSaleBan = preg_match('/(?:حظر بيع|حظر البيع)/u', $text);

        if ($asksFinanceCompany || $asksSaleBan) {
            $reply = $asksFinanceCompany ? 'التقسيط عندنا من خلال شركة أمان' : 'التقسيط عندنا';
            $reply .= $asksSaleBan ? ' ومن غير حظر بيع.' : '.';

            return ['messages' => [$reply]];
        }

        if (preg_match('/(?:الشحن|توصيل|بتشحنوا|بتوصلوا)/u', $text)
            && ! preg_match('/(?:سعر|بكام|كام يوم|امتى|فين|محافظة|محافظه)/u', $text)) {
            return ['messages' => ['الشحن متاح. كاش ولا تقسيط؟']];
        }

        if (preg_match('/(?:اول|أول) قسط/u', $text)
            && preg_match('/(?:امتى|متي|بعد كام|ميعاد|موعد)/u', $text)
            && is_numeric($days = config('agent.installments.first_payment_after_days'))) {
            return ['messages' => ['أول قسط بيكون بعد '.number_format((int) $days).' يوم من الاستلام.']];
        }

        if (preg_match('/(?:ضمان|الضمان)/u', $text)
            && preg_match('/(?:فيه|في |ايه|اي|كام|مدته|مدة)/u', $text)) {
            return ['messages' => ['تفاصيل الضمان بيأكدها الزميل في الفرع حسب الموديل. بتسأل على أنهي موديل؟']];
        }

        return null;
    }
}
