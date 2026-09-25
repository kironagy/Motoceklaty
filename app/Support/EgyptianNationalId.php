<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Structural reading of an Egyptian national ID (14 digits): birth date,
 * governorate and gender come from the number's own structure, not a
 * network call or a model guess.
 *
 * Layout: C YYMMDD GG SSSS X
 *   C      century marker - 2 = 1900s, 3 = 2000s
 *   YYMMDD birth date inside that century
 *   GG     governorate of registration
 *   SSSS   serial; its 3rd digit (position 13) is odd for male, even for female
 *   X      check digit
 *
 * Restored from `HEAD:app/Support/EgyptianNationalId.php` (git show), with
 * the hardcoded age window (MIN_AGE/MAX_AGE) and the Arabic reply-wording
 * helper (`problemMessage()`) removed: age eligibility is DEC-02, decided
 * once and read from `eligibility_rules` (T11's `age_range` evaluator),
 * never hardcoded here; and Laravel never writes customer-facing wording
 * (plan principle 1) - this class only exposes structured facts.
 */
class EgyptianNationalId
{
    /**
     * Registration governorate codes. A code outside this list means the
     * digits are not a real Egyptian ID, however many of them there are.
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
     * @return array{
     *     valid: bool,
     *     reason: ?string,
     *     digits: ?string,
     *     birthdate: ?string,
     *     age: ?int,
     *     governorate: ?string,
     *     gender: ?string
     * }
     */
    public function parse(?string $raw): array
    {
        $digits = $this->normalizeDigits((string) $raw);

        if ($digits === '') {
            return $this->invalid('empty', null);
        }

        if (strlen($digits) !== 14) {
            return $this->invalid(strlen($digits) < 14 ? 'too_short' : 'too_long', $digits);
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

        return [
            'valid' => true,
            'reason' => null,
            'digits' => $digits,
            'birthdate' => $birthdate->toDateString(),
            'age' => $birthdate->age,
            'governorate' => self::GOVERNORATES[$governorateCode],
            'gender' => ((int) $digits[12]) % 2 === 1 ? 'male' : 'female',
        ];
    }

    /**
     * Arabic-Indic and Eastern-Arabic digits both appear in what customers
     * type on WhatsApp; everything else is dropped so only the number
     * itself is judged.
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
        ];
    }
}
