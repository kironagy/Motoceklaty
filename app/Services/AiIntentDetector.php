<?php

namespace App\Services;

class AiIntentDetector
{
    public function detect(string $message): array
    {
        $text = $this->normalize($message);

        $scores = [
            'greeting' => 0,
            'branches' => 0,
            'installment_system' => 0,
            'installment_calc' => 0,
            'documents' => 0,
            'order' => 0,
            'followup' => 0,
            'specs' => 0,
            'comparison' => 0,
            'recommendation' => 0,
            'price' => 0,
            'general' => 0,
        ];

        $this->score($text, $scores, 'greeting', [
            'السلام عليكم' => 80,
            'سلام عليكم' => 80,
            'صباح الخير' => 60,
            'مساء الخير' => 60,
            'اهلا' => 40,
        ]);

        $this->score($text, $scores, 'branches', [
            'مكانكم' => 80,
            'فين مكانكم' => 90,
            'العنوان' => 70,
            'الفروع' => 80,
            'لوكيشن' => 80,
            'اقرب فرع' => 90,
        ]);

        $this->score($text, $scores, 'installment_system', [
            'نظام التقسيط' => 100,
            'انظمة التقسيط' => 100,
            'انظمه التقسيط' => 100,
            'ايه الانظمة المتاحة' => 100,
            'ايه الانظمه المتاحه' => 100,
            'الانظمة المتاحة' => 90,
            'الانظمه المتاحه' => 90,
            'بتقسطوا تبع ايه' => 100,
            'بتقسطو تبع ايه' => 100,
            'تقسيط تبع ايه' => 90,
            'شركات التمويل' => 80,
            'امان' => 60,
            'بتقسطوا ازاي' => 90,
            'بقسط ازاي' => 90,
        ]);

        $this->score($text, $scores, 'installment_calc', [
            'قسطها' => 90,
            'قسطه' => 90,
            'القسط كام' => 90,
            'قسط كام' => 90,
            'شهري' => 70,
            'كل شهر' => 70,
            'مقدم' => 70,
            'بدون مقدم' => 80,
            'من غير مقدم' => 80,
            '12 شهر' => 80,
            '18 شهر' => 80,
            '24 شهر' => 80,
            '36 شهر' => 80,
            'سنة' => 50,
            'سنه' => 50,
            'سنتين' => 70,
            'سنة ونص' => 80,
            'سنه ونص' => 80,
        ]);

        $this->score($text, $scores, 'documents', [
            'الورق' => 80,
            'اوراق' => 80,
            'المستندات' => 80,
            'المطلوب' => 50,
            'اقدم' => 50,
            'بطاقة' => 60,
            'بطاقه' => 60,
            'مفردات' => 70,
            'سجل تجاري' => 80,
            'بطاقة ضريبية' => 80,
            'كشف حساب' => 70,
        ]);

        $this->score($text, $scores, 'comparison', [
            'محتار بين' => 100,
            'اقارن' => 80,
            'مقارنة' => 80,
            'انهي احسن' => 90,
            'ايه الفرق' => 90,
            'ولا' => 30,
        ]);

        $this->score($text, $scores, 'recommendation', [
            'ترشحلي' => 100,
            'انسب' => 80,
            'احسن حاجة' => 80,
            'شغل وسفر' => 90,
            'استخدام شخصي' => 60,
            'عايز مكنة شغل' => 90,
        ]);

        $this->score($text, $scores, 'specs', [
            'مواصفات' => 80,
            'تفاصيل' => 60,
            'قطع غيار' => 70,
            'بنزين' => 60,
            'سفر' => 50,
            'شغل' => 40,
            'تستحمل' => 70,
            'عيوب' => 70,
            'مميزاتها' => 70,
        ]);

        $this->score($text, $scores, 'price', [
            'سعر' => 80,
            'بكام' => 90,
            'كام' => 40,
            'كاش' => 70,
            'تمن' => 60,
        ]);

        arsort($scores);

        $intent = array_key_first($scores);
        $score = (int) $scores[$intent];

        if ($score < 30) {
            $intent = 'general';
            $confidence = 'low';
        } elseif ($score < 70) {
            $confidence = 'medium';
        } else {
            $confidence = 'high';
        }

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'score' => $score,
            'scores' => $scores,
        ];
    }

    private function score(string $text, array &$scores, string $intent, array $keywords): void
    {
        foreach ($keywords as $keyword => $weight) {
            if (str_contains($text, $this->normalize($keyword))) {
                $scores[$intent] += $weight;
            }
        }
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        $text = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );

        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}