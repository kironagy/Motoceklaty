<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DocumentDataExtractor
{
    /**
     * Extract structured fields from OCR text and validate them against
     * the business rule text pulled from ai_memories (no hardcoded rules).
     *
     * @return array{ok:bool,valid:bool,fields:array,violation_message:?string}
     */
    public function extract(
        string $ocrText,
        string $documentType,
        string $rulesText,
        array $knownContext = []
    ): array {
        $ocrText = trim($ocrText);

        if ($ocrText === '') {
            return [
                'ok' => false,
                'valid' => false,
                'fields' => [],
                'violation_message' => 'مقدرتش أقرا بيانات من الصورة، ممكن تبعتها تاني بجودة أوضح؟',
            ];
        }

        $payload = [
            'document_type' => $documentType,
            'ocr_text' => $ocrText,
            'known_context' => $knownContext,
            'today' => now()->format('Y-m-d'),
        ];

        $prompt = <<<PROMPT
أنت نظام استخراج وتحقق بيانات مستندات لمعرض موتوسيكلات.

ممنوع ترد على العميل مباشرة. رجّع JSON فقط بدون أي شرح.

استخرج من نص الـ OCR كل البيانات المتاحة (اسم، رقم قومي، تاريخ ميلاد،
مرتب، تاريخ تحرير المستند، تاريخ تعيين، تاريخ إصدار سجل تجاري/بطاقة
ضريبية...إلخ حسب نوع المستند).

بعد الاستخراج، طبّق القواعد النصية دي بالظبط (القواعد جاية من لوحة تحكم
العميل ومحتواها هو المرجع الوحيد، ما تخترعش قواعد إضافية):

--- القواعد ---
{$rulesText}
--- نهاية القواعد ---

مهم جدًا:
- التحقق (valid) بيكون بس عن حاجات موجودة أو ممكن تتأكد منها من نص
  المستند نفسه (وضوح البيانات، الرقم القومي كامل، تاريخ منطقي، مطابقة
  القواعد المذكورة فوق).
- ممنوع تطلب في violation_message أي بيانات مالهاش علاقة بالمستند ده
  نفسه (زي رقم تليفون إضافي، عناوين تانية، تاريخ قروض سابقة) حتى لو
  مذكورة في مكان تاني — دي بتتجمع في خطوات تانية من المحادثة مش هنا.
- لو المستند واضح ومطابق لنوعه ومفيهوش مخالفة من القواعد، خليه valid=true
  وviolation_message=null، حتى لو فيه بيانات تانية للعميل لسه ناقصة في
  الطلب ككل.

التاريخ النهارده: {$payload['today']}

رجّع JSON بهذا الشكل فقط:
{
  "fields": { "أي حقول اتقرت من المستند": "قيمتها" },
  "valid": true أو false,
  "violation_message": "شرح بالعربي المصري لو فيه مخالفة، وإلا null"
}

بيانات المستند:
PROMPT
            . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = app(GeminiClient::class)->generateText($prompt, null, [
                'temperature' => 0.05,
                'maxOutputTokens' => 800,
            ]);

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'valid' => false,
                    'fields' => [],
                    'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
                ];
            }

            $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

            if (! is_array($json)) {
                return [
                    'ok' => false,
                    'valid' => false,
                    'fields' => [],
                    'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
                ];
            }

            return [
                'ok' => true,
                'valid' => (bool) ($json['valid'] ?? false),
                'fields' => is_array($json['fields'] ?? null) ? $json['fields'] : [],
                'violation_message' => $json['violation_message'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('DocumentDataExtractor failed', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'valid' => false,
                'fields' => [],
                'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
            ];
        }
    }

    private function extractJson(string $text): ?array
    {
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', trim((string) $text));

        if (preg_match('/\{.*\}/su', (string) $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode((string) $text, true);

        return is_array($data) ? $data : null;
    }
}
