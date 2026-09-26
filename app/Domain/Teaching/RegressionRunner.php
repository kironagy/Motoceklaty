<?php

namespace App\Domain\Teaching;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Domain\Simulation\ConversationSimulator;
use App\Models\BotLesson;
use App\Models\TeachingCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class RegressionRunner
{
    public function __construct(
        private readonly ConversationSimulator $simulator,
        private readonly AiProvider $ai,
    ) {
    }

    /** @return array{pass: bool, reply: string, reason: string} */
    public function run(TeachingCase $case): array
    {
        $history = array_values((array) $case->history);
        $last = array_pop($history) ?? ['role' => 'customer', 'text' => ''];

        $conversation = $this->simulator->start('اختبار تعليم #'.$case->id, $this->simulator->checkBot());

        foreach ($history as $message) {
            $this->simulator->seed($conversation, $message['role'] === 'customer' ? 'incoming' : 'outgoing', (string) ($message['text'] ?? ''));
        }

        $paths = collect($last['media'] ?? [])
            ->map(fn ($m) => Storage::disk($m['disk'] ?? 'local')->path($m['path']))
            ->filter(fn ($p) => is_file($p))
            ->values()->all();

        $result = $this->simulator->send($conversation, (string) ($last['text'] ?? ''), $paths);
        $reply = trim(implode("\n", $result['reply'] ?? []));

        $verdict = $result['error']
            ? ['pass' => false, 'reason' => 'البوت ما ردش: '.$result['error']]
            : $this->judge($case, (string) ($last['text'] ?? ''), $reply);

        $case->update([
            'last_result' => $verdict['pass'] ? 'pass' : 'fail',
            'last_reply' => $reply,
            'last_reason' => $verdict['reason'],
            'last_run_at' => now(),
        ]);

        return ['pass' => $verdict['pass'], 'reply' => $reply, 'reason' => $verdict['reason']];
    }

    /** Old cases closest to the given one: same stage first, then the most recent. */
    public function related(TeachingCase $case, int $limit): Collection
    {
        return TeachingCase::where('is_active', true)
            ->where('id', '!=', $case->id)
            ->orderByRaw('CASE WHEN scope_stage = ? THEN 0 ELSE 1 END', [(string) $case->scope_stage])
            ->latest('id')
            ->take($limit)
            ->get();
    }

    /** @return array{pass: bool, reason: string} */
    public function judge(TeachingCase $case, string $customerMessage, string $reply): array
    {
        if ($reply === '') {
            return ['pass' => false, 'reason' => 'البوت ما ردش بأي كلام'];
        }

        $lesson = $case->bot_lesson_id ? BotLesson::find($case->bot_lesson_id) : null;

        if ($lesson?->example_reply && $this->copied($lesson, $reply)) {
            return ['pass' => false, 'reason' => 'البوت نسخ مثال الدرس بالنص بدل ما يكتبه بأسلوبه'];
        }

        try {
            $response = $this->ai->chat(new AiRequest(
                system: "إنت حَكَم بتراجع رد بوت مبيعات موتوسيكلات مصري. قرر هل الرد بيحقق المطلوب.\n"
                    ."- احكم على المعنى والتصرف مش على الكلمات بالظبط.\n"
                    ."- الثوابت المطلوبة لازم تبان في الرد (مسموح اختلاف بسيط في الإملا).\n"
                    ."- لو الرد بيعمل حاجة المطلوب بيقول ممنوع، يبقى فشل.\n"
                    .'- السبب جملة قصيرة بالمصري.',
                contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => "المطلوب: {$case->expectation}\n"
                    .'ثوابت لازم تتقال: '.(implode('، ', (array) $case->must_contain) ?: 'مفيش')."\n\n"
                    ."رسالة العميل: {$customerMessage}\n\nرد البوت:\n{$reply}"]]]],
                toolMode: 'none',
                temperature: 0.0,
                maxOutputTokens: 300,
                thinkingBudget: 0,
                responseSchema: [
                    'type' => 'object',
                    'properties' => ['pass' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
                    'required' => ['pass', 'reason'],
                ],
            ));

            $json = json_decode(implode('', $response->textParts), true);

            return ['pass' => (bool) ($json['pass'] ?? false), 'reason' => (string) ($json['reason'] ?? '')];
        } catch (\Throwable $e) {
            return ['pass' => false, 'reason' => 'الحكم ما اشتغلش: '.$e->getMessage()];
        }
    }

    private function copied(BotLesson $lesson, string $reply): bool
    {
        $strip = function (string $text) use ($lesson): string {
            foreach ((array) $lesson->fixed_facts as $fact) {
                $text = str_replace((string) $fact, '', $text);
            }

            return preg_replace('/\s+/u', ' ', trim($text));
        };

        $a = $strip($lesson->example_reply);
        $b = $strip($reply);

        if (mb_strlen($a) < 25) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 92;
    }
}
