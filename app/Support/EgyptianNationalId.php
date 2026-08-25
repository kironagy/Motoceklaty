<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Structural reading of an Egyptian national ID (14 digits), so the bot
 * knows the applicant's birth date and age from the number itself instead
 * of waiting for the ID-card image OCR at the very end of the flow.
 *
 * Layout: C YYMMDD GG SSSS X
 *   C      century marker - 2 = 1900s, 3 = 2000s (1 = 1800s, 4 = 2100s
 *          exist in the spec but can never be a living applicant here)
 *   YYMMDD birth date inside that century
 *   GG     governorate of registration
 *   SSSS   serial; its 3rd digit (position 13) is odd for male, even for
 *          female
 *   X      check digit
 *
 * Everything here is pure structure - no network call, no LLM. The number
 * either decodes to a real calendar date in a real governorate or it does
 * not, and that answer must never depend on a model's mood.
 */
class EgyptianNationalId
{
    /**
     * Registration governorate codes. A code outside this list means the
     * digits are not a real Egyptian ID, however many of them there are -
     * this is what catches a phone number or an order number typed into
     * the national-ID answer while still being 14 digits long.
     */
    public const GOVERNORATES = [
        '01' => 'القاهرة',
        '02' => 'الإسكندرية',
        '03' => 'بورسعيد',
        '04' => 'السويس',
        '11' => 'دمياط',
        '12' => 'الدقهلية',
        '13' => 'الشرقية',
        '14' => 'القليوبية',
        '15' => 'كفر الشيخ',
        '16' => 'الغربية',
        '17' => 'المنوفية',
        '18' => 'البحيرة',
        '19' => 'الإسماعيلية',
        '21' => 'الجيزة',
        '22' => 'بني سويف',
        '23' => 'الفيوم',
        '24' => 'المنيا',
        '25' => 'أسيوط',
        '26' => 'سوهاج',
        '27' => 'قنا',
        '28' => 'أسوان',
        '29' => 'الأقصر',
        '31' => 'البحر الأحمر',
        '32' => 'الوادي الجديد',
        '33' => 'مطروح',
        '34' => 'شمال سيناء',
        '35' => 'جنوب سيناء',
        '88' => 'خارج الجمهورية',
    ];

    /**
     * Financing age window, same numbers the dashboard form already
     * applies when a request is created by hand
     * (InstallmentRequestController: age >= 21 && age <= 62). Kept here as
     * the single definition the WhatsApp flow reads, so the two paths
     * cannot drift apart.
     */
    public const MIN_AGE = 21;
    public const MAX_AGE = 62;

    /**
     * @return array{
     *     valid: bool,
     *     reason: ?string,
     *     digits: ?string,
     *     birthdate: ?string,
     *     age: ?int,
     *     governorate: ?string,
     *     gender: ?string,
     *     age_ok: bool
     * }
     */
    public function parse(?string $raw): array
    {
        $digits = $this->normalizeDigits((string) $raw);

        if ($digits === '') {
            return $this->invalid('empty', null);
        }

        if (strlen($digits) !== 14) {
            return $this->invalid(
                strlen($digits) < 14 ? 'too_short' : 'too_long',
                $digits
            );
        }

        $century = match ($digits[0]) {
            '2' => 1900,
            '3' => 2000,
            default => null,
        };

        if ($century === null) {
            return $this->invalid('bad_century', $digits);
        }

        $year = $century + (int) substr($digits, 1, 2);
        $month = (int) substr($digits, 3, 2);
        $day = (int) substr($digits, 5, 2);

        if (! checkdate($month, $day, $year)) {
            return $this->invalid('bad_birthdate', $digits);
        }

        $birthdate = Carbon::create($year, $month, $day);

        if ($birthdate === null || $birthdate->isFuture()) {
            return $this->invalid('bad_birthdate', $digits);
        }

        $governorateCode = substr($digits, 7, 2);

        if (! isset(self::GOVERNORATES[$governorateCode])) {
            return $this->invalid('bad_governorate', $digits);
        }

        $age = $birthdate->age;

        return [
            'valid' => true,
            'reason' => null,
            'digits' => $digits,
            'birthdate' => $birthdate->toDateString(),
            'age' => $age,
            'governorate' => self::GOVERNORATES[$governorateCode],
            'gender' => ((int) $digits[12]) % 2 === 1 ? 'male' : 'female',
            'age_ok' => $age >= self::MIN_AGE && $age <= self::MAX_AGE,
        ];
    }

    /**
     * The customer-facing line for a number that decoded fine but puts the
     * applicant outside the financing age window, or for one that did not
     * decode at all. Returns null when nothing is wrong, so the caller can
     * use it directly as "is there something to say about this ID".
     */
    public function problemMessage(array $parsed): ?string
    {
        if ($parsed['valid'] ?? false) {
            if ($parsed['age_ok'] ?? false) {
                return null;
            }

            $age = (int) ($parsed['age'] ?? 0);

            if ($age < self::MIN_AGE) {
                return 'الرقم القومي اللي حضرتك بعته بيقول إن سنك ' . $age . ' سنة، '
                    . 'وللأسف التقسيط عندنا بيبدأ من ' . self::MIN_AGE . ' سنة. '
                    . 'لو الرقم مكتوب غلط ابعته تاني، أو ممكن حد من الأهل فوق ' . self::MIN_AGE
                    . ' سنة يقدّم بالبطاقة بتاعته.';
            }

            return 'الرقم القومي اللي حضرتك بعته بيقول إن سنك ' . $age . ' سنة، '
                . 'والتقسيط عندنا لحد ' . self::MAX_AGE . ' سنة. '
                . 'لو الرقم مكتوب غلط ابعته تاني، أو ممكن حد من الأهل تحت ' . self::MAX_AGE
                . ' سنة يقدّم بالبطاقة بتاعته.';
        }

        return match ($parsed['reason'] ?? null) {
            'too_short' => 'الرقم القومي اللي بعته ناقص - لازم يكون ١٤ رقم بالظبط زي ما هو مكتوب في البطاقة. ابعته تاني من فضلك.',
            'too_long' => 'الرقم القومي اللي بعته زيادة عن ١٤ رقم. ابعتلي الرقم زي ما هو مكتوب في البطاقة بالظبط.',
            'bad_century' => 'الرقم القومي ده مش شكله رقم قومي مصري صحيح (أول رقم فيه لازم يكون ٢ أو ٣). راجعه وابعته تاني من فضلك.',
            'bad_birthdate' => 'الرقم القومي ده تاريخ الميلاد اللي جواه مش تاريخ حقيقي، يعني فيه رقم غلط. راجعه من البطاقة وابعته تاني.',
            'bad_governorate' => 'الرقم القومي ده كود المحافظة اللي جواه مش موجود، يعني فيه رقم غلط. راجعه من البطاقة وابعته تاني.',
            default => 'محتاج الرقم القومي زي ما هو مكتوب في البطاقة (١٤ رقم).',
        };
    }

    /**
     * Arabic-Indic and Eastern-Arabic digits both appear in what customers
     * type on WhatsApp; everything else (spaces, dashes, "الرقم القومي")
     * is dropped so only the number itself is judged.
     */
    public function normalizeDigits(string $value): string
    {
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function invalid(string $reason, ?string $digits): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'digits' => $digits,
            'birthdate' => null,
            'age' => null,
            'governorate' => null,
            'gender' => null,
            'age_ok' => false,
        ];
    }
}
