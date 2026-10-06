<?php

namespace App\Domain\Applications;

/**
 * Owner 2026-10-05: when the customer himself cannot apply (age, no work),
 * the only way is another person applying in his own name - said exactly
 * this way. The bot asked "اسمه وصلته بيك (أخ/أخويا/حبيب/صاحب...)" and
 * "ساكن" - questions nobody asked it to invent. Tool results carry this line
 * and the agent says it as is.
 */
class OtherApplicant
{
    /** Refusal codes after which someone else may apply instead of him. */
    public const CODES = ['AGE_OUT_OF_RANGE', 'AGE_BELOW_MINIMUM_STATED', 'APPLICANT_HAS_NO_WORK'];

    /**
     * @param bool $refusedIsOther the refused person is already the one applying
     *   instead of him (conversation 206: his mother "مش شغاله", his brother
     *   "لسه هيشتغل") - "حد تاني يقدّم بدالك" then makes no sense.
     */
    public static function line(bool $refusedIsOther = false): string
    {
        return $refusedIsOther
            ? 'للأسف اللي هيقدّم لازم يكون شغال أو على المعاش وسنه من 21 لـ 62. فيه حد تاني كده يقدر يقدّم؟'
            : 'ينفع حد تاني يقدّم بدالك، بس الطلب كله يبقى باسمه هو وبمستنداته هو، ولازم سنه من 21 لـ 62 ويكون شغال أو على المعاش. هو بيشتغل إيه؟';
    }

    /** @param string[] $codes */
    public static function appliesTo(array $codes): bool
    {
        return array_intersect($codes, self::CODES) !== [];
    }
}
