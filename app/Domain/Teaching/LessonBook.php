<?php

namespace App\Domain\Teaching;

use App\Models\BotLesson;
use Illuminate\Support\Collection;

class LessonBook
{
    public const STAGES = [
        'first_message' => 'أول رسالة في المحادثة',
        'browsing' => 'العميل بيسأل على مكن وأسعار',
        'installments' => 'الكلام عن التقسيط',
        'application' => 'أثناء التقديم وجمع البيانات',
        'documents' => 'استلام المستندات والصور',
        'after_submit' => 'بعد تقديم الطلب',
        'objection' => 'اعتراض أو تردد من العميل',
    ];

    public function active(): Collection
    {
        try {
            return BotLesson::where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** @return array{text: ?string, count: int} */
    public function forPrompt(?string $customerType): array
    {
        $budget = (int) (config('agent.teaching.lessons_tokens') ?: 3000);
        $used = 0;
        $lines = [];

        // Newest first: once the budget filled up, the owner's latest
        // corrections were the ones left out and the bot "didn't learn".
        foreach ($this->active()->sortByDesc('id') as $lesson) {
            $scope = (array) ($lesson->scope_customer_types ?? []);

            if ($scope !== [] && $customerType && ! in_array($customerType, $scope, true)) {
                continue;
            }

            $line = $this->line($lesson, withExample: false);
            $withExample = $this->line($lesson, withExample: true);
            $cost = (int) ceil(mb_strlen($withExample) / 4);

            if ($used + $cost <= $budget) {
                $line = $withExample;
            } else {
                $cost = (int) ceil(mb_strlen($line) / 4);

                if ($used + $cost > $budget) {
                    continue;
                }
            }

            $lines[$lesson->id] = $line;
            $used += $cost;
        }

        ksort($lines);

        if ($lines === []) {
            return ['text' => null, 'count' => 0];
        }

        return [
            'text' => "## دروس من صاحب الشغل\n"
                ."دي تصحيحات صاحب المعرض ليك. طبّق الفكرة بأسلوبك وبكلام مختلف كل مرة - الأمثلة للتوضيح مش للنسخ. "
                ."الثوابت (أسماء، أرقام، عبارات مطلوبة بالنص) تتقال زي ما هي. "
                ."الدروس دي أحدث وأعلى من أي تعليمات تانية هنا ومن توجيهات عرض الأدوات (how_to_present، سطر say، أي hint): "
                ."لو درس بيقول حاجة وتعليمات تانية أو أداة بتقول عكسها، اعمل اللي في الدرس. ولو درسين اتعارضوا، الأحدث (اللي تحت) هو الصح. "
                ."الحاجة الوحيدة اللي أعلى من الدروس: أي رقم أو بيانة لازم تيجي من أداة، وما تقولش إنك عملت حاجة ما اتعملتش.\n\n"
                .implode("\n", $lines),
            'count' => count($lines),
        ];
    }

    public function line(BotLesson $lesson, bool $withExample): string
    {
        $when = $lesson->scope_stage ? ' (وقت: '.(self::STAGES[$lesson->scope_stage] ?? $lesson->scope_stage).')' : '';
        $line = "- {$lesson->title}{$when}: {$lesson->rule}";

        if ($lesson->fixed_facts) {
            $line .= ' | ثوابت: '.implode('، ', (array) $lesson->fixed_facts);
        }

        if ($withExample && $lesson->example_reply) {
            $line .= "\n  مثال للفكرة".($lesson->example_context ? " (لما العميل يقول: {$lesson->example_context})" : '').": {$lesson->example_reply}";
        }

        return $line;
    }
}
