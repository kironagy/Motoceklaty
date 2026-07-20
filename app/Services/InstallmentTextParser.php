<?php

namespace App\Services;

class InstallmentTextParser
{
    public function parse(string $message): array
    {
        $text = $this->normalize($message);

        return [
            'months' => $this->extractMonths($text),
            'deposit' => $this->extractDeposit($text),
            'deposit_mentioned' => $this->hasDepositMention($text),
            'system' => $this->extractSystem($text),
            'no_admin_fee_requested' => $this->wantsNoAdminFee($text),
        ];
    }

    public function extractMonths(string $text): ?int
    {
        $text = $this->normalize($text);

        if (preg_match('/(?:سنه\s*ونص|سنة\s*ونص|سنه ونصف|سنة ونصف|18\s*شهر)/u', $text)) {
            return 18;
        }

        if (preg_match('/(?:3\s*سنين|٣\s*سنين|ثلاث\s*سنين|تلات\s*سنين|36\s*شهر)/u', $text)) {
            return 36;
        }

      if (preg_match('/(?:سنتين|سنتان|عامين|2\s*سنه|2\s*سنة|24\s*شهر)/u', $text)) {
    return 24;
}

        if (preg_match('/(?:سنه|سنة|12\s*شهر)/u', $text)) {
            return 12;
        }

        if (preg_match('/(\d+)\s*(?:شهر|شهور)/u', $text, $m)) {
            $months = (int) $m[1];

            if (in_array($months, [6, 12, 18, 24,36], true)) {
                return $months;
            }

            if ($months > 0 && $months <= 36) {
                return $months;
            }
        }

        return null;
    }

    public function extractDeposit(string $text): float
    {
        $text = $this->normalize($text);

        if (preg_match('/(?:من غير مقدم|بدون مقدم|مفيش مقدم|صفر مقدم)/u', $text)) {
            return 0;
        }

        if (preg_match('/(?:مقدم|هدفع|ادفع|دافع)\s*(\d+(?:\.\d+)?)/u', $text, $m)) {
            return (float) $m[1];
        }

        return 0;
    }

    public function hasDepositMention(string $text): bool
    {
        $text = $this->normalize($text);

        return (bool) preg_match('/(?:مقدم|من غير مقدم|بدون مقدم|مفيش مقدم|صفر مقدم|هدفع|ادفع|دافع)/u', $text);
    }

    public function extractSystem(string $text): string
    {
        $text = $this->normalize($text);

        if ($this->wantsNoAdminFee($text)) {
            return '30';
        }

        if (preg_match('/(?:نظام\s*30|٣٠|30\s*%|30%)/u', $text)) {
            return '30';
        }

        return '20';
    }

    public function wantsNoAdminFee(string $text): bool
    {
        $text = $this->normalize($text);

        return (bool) preg_match('/(?:مش عايز مصاريف|بدون مصاريف|من غير مصاريف|مش هدفع مصاريف|الغى المصاريف)/u', $text);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);

        $text = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9'],
            $text
        );

        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s\.]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
