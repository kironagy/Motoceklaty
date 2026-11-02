<?php

namespace App\Agent\Runtime;

use App\Support\ArabicTextNormalizer;

/** Stable answer for a bare installment question with no model or requirements. */
class GenericInstallmentReply
{
    public function for(string $text): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', ArabicTextNormalizer::normalize($text)));

        if (mb_strlen($text) <= 100 && preg_match('/قسط|تقسيط|اقساط/u', $text)
            && ! preg_match('/\d|[a-z]|موديل|سنتين|سنه|سنين|مقدم|فايد|فائد|ورق|بطاق|شغل|موظف|معاش|هوج|دايو|بوكسر|فجور|فيجور|سكوتر/u', $text)
            && preg_match('/تفصيل|تفاصيل|نظام|في مكنه|فيه|مفيش/u', $text)) {
            return ['messages'=>['القسط بيتحسب على حسب الموديل. بتسأل على قسط أنهي موديل؟']];
        }

        // Deliberately narrow: richer requests (work/use/budget/no down
        // payment/model name) still go through the agent and its tools.
        if (! preg_match('/^(?:(?:انا |كنت )?عايز |ممكن )?(?:اعرف )?(?:تفاصيل |نظام |انظمه )?(?:ال)?(?:قسط|تقسيط)(?: ايه| اي| ازاي)?[؟?]*$/u', $text)) {
            return null;
        }

        return ['messages' => ['القسط بيتحسب على حسب الموديل. بتسأل على قسط أنهي موديل؟']];
    }
}
