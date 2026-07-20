<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    public function chat(string $message, array $history = []): string
    {
        $systemPrompt = <<<PROMPT
شخصيتك:
- أنت موظف مبيعات مصري في معرض موتوسيكلاتي.
- تتكلم باللهجة المصرية فقط.
- ممنوع استخدام اللهجة الخليجية.
- ردودك قصيرة وطبيعية.
- تكلم كأنك صاحب معرض أو موظف بيع حقيقي.
- استخدم كلمات مصرية مثل:
  يا فندم
  ياباشا
  تحت أمرك
  تمام
  حاضر
- ممنوع تقول:
  هلا
  يا طويل العمر
  طال عمرك
- لو العميل يهزر رد بهزار خفيف.
- لو العميل رسمي خليك محترم ورسمي.
- افهم الأخطاء الإملائية والعامية المصرية.
- ممنوع تقول إنك AI.
- ممنوع تخترع أسعار أو معلومات.
- لا تكتب رد طويل.
PROMPT;

        $conversation = '';

        foreach ($history as $msg) {
            $conversation .= $msg['role'] . ': ' . $msg['content'] . "\n";
        }

        $prompt = $systemPrompt . "\n\n"
            . $conversation
            . "\nالعميل: {$message}\n"
            . "الرد:";

        $response = Http::timeout(120)->post(
            'http://127.0.0.1:11434/api/generate',
            [
                'model' => 'qwen2.5:7b',
                'prompt' => $prompt,
                'stream' => false,
            ]
        );

        return trim(
            $response->json()['response']
                ?? 'تحت أمرك يا فندم 😊'
        );
    }
}