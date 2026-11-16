<?php

namespace App\Domain\Aman;

use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use App\Models\InstallmentRequest;
use App\Support\EgyptianNationalId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the applicant's national ID card (front + back) for the Aman uploader:
 * Google Vision text, then fixed rules for an Egyptian card. Values that the
 * national ID number itself encodes (birthdate, gender, governorate) come from
 * the number, never from OCR guesses. OCR text is never logged.
 *
 * Result keys: name_1..name_4, full_name, national_id, birthdate, gender,
 * governorate, address, religion, marital_status, issue_date, expiry_date,
 * plus `name_source` (card|system) and `errors`.
 */
class IdCardReader
{
    private const HEADER = ['جمهوريه مصر العربيه', 'بطاقه تحقيق الشخصيه', 'جمهوريه مصر العربيه بطاقه تحقيق الشخصيه'];

    public function __construct(private OcrProvider $ocr, private EgyptianNationalId $nid)
    {
    }

    public function read(InstallmentRequest $request): array
    {
        $front = $request->applicant_id_image;
        $back = $request->applicant_id_back_image;
        // "v3": cards read before the address fixes are read again.
        $key = 'aman-card:v6:'.$request->id.':'.md5($front.'|'.$back.'|'.$request->applicant_name.'|'.$request->applicant_national_id);

        return Cache::remember($key, now()->addDays(30), fn () => $this->readFresh($request, $front, $back));
    }

    private function readFresh(InstallmentRequest $request, ?string $front, ?string $back): array
    {
        $errors = [];
        $frontText = $this->text($front, 'front', $errors);
        $backText = $this->text($back, 'back', $errors);

        $out = $this->parseFront($frontText) + $this->parseBack($backText);

        // The number: the card's OCR read, kept as "البطاقة: الرقم القومي" for recipes that want it. A
        // mismatch with motobot's is not reported (owner 2026-11-17: Aman reads the number itself with
        // its OCR Scan button, recipes carry no national ID step). Birthdate / gender come from motobot's number when valid.
        $systemNid = $this->nid->normalizeDigits((string) $request->applicant_national_id);
        $systemValid = strlen($systemNid) === 14 && $this->nid->parse($systemNid)['valid'];
        if (empty($out['national_id']) && $systemValid) {
            $out['national_id'] = $systemNid;
        }

        $parsed = $this->nid->parse($systemValid ? $systemNid : ($out['national_id'] ?? null));
        if ($parsed['valid']) {
            $out['birthdate'] = $parsed['birthdate'];
            $out['governorate'] = $parsed['governorate'];
            $cardGender = $out['gender'] ?? null;
            $out['gender'] = $parsed['gender'] === 'male' ? 'ذكر' : 'أنثى';
            if ($cardGender && $cardGender !== $out['gender']) {
                $errors[] = 'النوع المكتوب في البطاقة غير اللي في الرقم القومي';
            }
        } elseif (! empty($out['national_id'])) {
            $errors[] = 'الرقم القومي مش سليم';
        }

        // Name: the card's two name lines; else the system's single name split into 4.
        if (empty($out['name_1'])) {
            $out = array_merge($out, $this->splitName((string) $request->applicant_name));
            $out['name_source'] = 'system';
        } else {
            $out['name_source'] = 'card';
            $out = $this->fixNameFromSystem($out, (string) $request->applicant_name, $errors);
        }
        $out['full_name'] = trim(implode(' ', array_filter([$out['name_1'] ?? '', $out['name_2'] ?? '', $out['name_3'] ?? '', $out['name_4'] ?? ''])));

        $out['errors'] = $errors;

        return $out;
    }

    private function text(?string $path, string $side, array &$errors): string
    {
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            $errors[] = $side === 'front' ? 'صورة وش البطاقة مش موجودة' : 'صورة ضهر البطاقة مش موجودة';

            return '';
        }

        try {
            $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

            $bytes = Storage::disk('public')->get($path);
            $result = $this->ocr->extractText(base64_encode($bytes), $mime);
            // IdCardImage straightens the same photo for Aman with these word positions.
            Cache::put('aman-words:'.md5($bytes), $result->words, now()->addDays(30));

            return $result->text;
        } catch (OcrException $e) {
            Log::warning('Aman card OCR failed', ['side' => $side, 'error' => $e->getMessage()]);
            $errors[] = 'قراءة '.($side === 'front' ? 'وش' : 'ضهر').' البطاقة فشلت';

            return '';
        }
    }

    /** Front: header, first-name line, rest-of-name line, address lines, the number. */
    public function parseFront(string $text): array
    {
        $out = [];
        if ($text === '') {
            return $out;
        }

        $lines = $this->lines($text);
        $nidLine = null;
        foreach ($lines as $i => $line) {
            $digits = $this->nid->normalizeDigits($line);
            if (preg_match('/[23]\d{13}/', $digits, $m)) {
                $out['national_id'] = $m[0];
                $nidLine = $i;
                break;
            }
        }

        // The two name lines have no digits. The address lines after them may ("٣٣ش صبحى النجار-جمال
        // عبدالناصر"); the birth date ("٢٠٠٥/٠١/١٣") and the Latin serial never pass.
        $words = [];
        foreach ($lines as $i => $line) {
            if ($nidLine !== null && $i >= $nidLine) {
                break;
            }
            $isName = count($words) < 2;
            $allowed = $isName ? '/^[\p{Arabic}\s\-\.،]+$/u' : '/^[\p{Arabic}\d\s\-\.،]+$/u';
            if (in_array($this->norm($line), self::HEADER, true) || ! preg_match($allowed, $line)
                || ($isName && preg_match('/\d/u', $line)) || ! preg_match('/\p{L}/u', $line)) {
                continue;
            }
            $words[] = trim($line);
        }

        if (count($words) >= 2) {
            $rest = preg_split('/\s+/u', $words[1]);
            $out['name_1'] = $words[0];
            $out['name_2'] = $rest[0] ?? '';
            $out['name_3'] = $rest[1] ?? '';
            $out['name_4'] = trim(implode(' ', array_slice($rest, 2)));
            // The whole card address, both lines as printed (owner 2026-11-16: an exact copy).
            $address = array_slice($words, 2);
            if ($address) {
                // Western digits: Aman drops Vision's Persian "۱۸" (request 5273 lost its building number).
                $out['address'] = strtr(implode(' - ', $address), [
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                ]);
            }
        }

        return $out;
    }

    /** Back: gender, religion, marital status, issue and expiry dates. */
    public function parseBack(string $text): array
    {
        $out = [];
        if ($text === '') {
            return $out;
        }

        $n = $this->norm($text);
        if (preg_match('/(^|\s)(ذكر|انثي)(\s|$)/u', $n, $m)) {
            $out['gender'] = $m[2] === 'ذكر' ? 'ذكر' : 'أنثى';
        }
        if (preg_match('/(^|\s)(مسلم|مسيحي)(ه)?(\s|$)/u', $n, $m)) {
            $out['religion'] = $m[2] === 'مسلم' ? 'مسلم' : 'مسيحي';
        }
        foreach (['متزوج' => 'متزوج', 'اعزب' => 'أعزب', 'انسه' => 'آنسة', 'مطلق' => 'مطلق', 'ارمل' => 'أرمل'] as $needle => $label) {
            if (preg_match('/(^|\s)'.$needle.'(ه)?(\s|$)/u', $n, $m)) {
                $out['marital_status'] = $label.(! empty($m[2]) && $needle !== 'انسه' ? 'ة' : '');
                break;
            }
        }

        $latin = strtr($n, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (preg_match('/ساري?ه?\s*حتي\s*:?\s*(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}|\d{4}[\/\-.]\d{1,2}([\/\-.]\d{1,2})?)/u', $latin, $m)) {
            $out['expiry_date'] = $this->date($m[1]);
        }
        if (preg_match_all('/\b(\d{4})[\/\-.](\d{1,2})\b/u', $latin, $all, PREG_SET_ORDER)) {
            $out['issue_date'] = sprintf('%04d-%02d', $all[0][1], $all[0][2]);
        }

        return $out;
    }

    /**
     * The card reader sometimes drops letters ("مد" for "محمد"). A card name that is the system
     * name with two or more letters missing is taken from the system; any other difference is
     * only reported, because the card is the legal spelling ("حسن" vs "حسين").
     */
    public function fixNameFromSystem(array $out, string $systemName, array &$errors): array
    {
        $system = preg_split('/\s+/u', trim($systemName)) ?: [];
        foreach (['name_1', 'name_2', 'name_3'] as $i => $key) {
            $card = trim($out[$key] ?? '');
            $sys = $system[$i] ?? '';
            if ($card === '' || $sys === '' || $this->norm($card) === $this->norm($sys)) {
                continue;
            }
            $c = mb_str_split($this->norm($card));
            $t = mb_str_split($this->norm($sys));
            if (count($t) - count($c) >= 2 && $this->isSubsequence($c, $t)) {
                $errors[] = 'الاسم في البطاقة اتقرا "'.$card.'" واتصلح من السيستم "'.$sys.'"';
                $out[$key] = $sys;
            } else {
                $errors[] = 'الاسم في البطاقة "'.$card.'" والسيستم "'.$sys.'"، راجعه';
            }
        }

        return $out;
    }

    private function isSubsequence(array $short, array $long): bool
    {
        $j = 0;
        foreach ($long as $ch) {
            if ($j < count($short) && $short[$j] === $ch) {
                $j++;
            }
        }

        return $j === count($short);
    }

    public function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return [
            'name_1' => $parts[0] ?? '',
            'name_2' => $parts[1] ?? '',
            'name_3' => $parts[2] ?? '',
            'name_4' => trim(implode(' ', array_slice($parts, 3))),
        ];
    }

    private function date(string $raw): string
    {
        $p = preg_split('/[\/\-.]/', $raw);
        if (strlen($p[0]) === 4) {
            return sprintf('%04d-%02d', $p[0], $p[1]).(isset($p[2]) ? sprintf('-%02d', $p[2]) : '');
        }

        return sprintf('%04d-%02d-%02d', $p[2], $p[1], $p[0]);
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), preg_split('/\R/u', $text)), fn ($l) => $l !== ''));
    }

    private function norm(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
