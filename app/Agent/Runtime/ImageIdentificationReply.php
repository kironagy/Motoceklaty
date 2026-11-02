<?php

namespace App\Agent\Runtime;

/** Formats the vision result without another full-prompt model call. */
class ImageIdentificationReply
{
    public function for(array $result): array
    {
        $data = (array) ($result['data'] ?? []);
        $name = trim((string) data_get($data, 'candidates.0.name', ''));
        $confidence = (float) data_get($data, 'candidates.0.confidence', 0);
        $identifiedBy = (string) ($data['identified_by'] ?? '');

        // A readable name/logo with catalog-level confidence is direct
        // evidence. Do not turn a correct recognition into a vague answer
        // merely because other motorcycles happen to be in the background.
        if ($name !== '' && $identifiedBy === 'visible_name_or_logo' && $confidence >= 0.8) {
            return ['messages' => ["دي {$name}، والموديل موجود في الكتالوج. التوفر الفعلي بيأكدّه قسم الكاش."]];
        }

        return match ($data['band'] ?? 'unknown') {
            'match' => ['messages' => [$name !== ''
                ? "دي {$name}، والموديل موجود في الكتالوج. التوفر الفعلي بيأكدّه قسم الكاش."
                : 'قدرت أحدد شكل المكنة، بس الاسم مش واضح. ابعتلي صورة للاسم أو اللوجو.']],
            'similar' => ['messages' => [$name !== ''
                ? "الصورة أقرب لـ {$name}، بس الاسم مش ظاهر بوضوح عشان أأكد الموديل. ابعتلي صورة للاسم أو اللوجو."
                : 'الصورة مش كفاية عشان أحدد الموديل. ابعتلي صورة أوضح للاسم أو اللوجو.']],
            'not_in_catalog' => ['messages' => [filled($observed = data_get($data, 'observed.model'))
                ? "المكنة الظاهرة أقرب لـ {$observed}، والموديل ده مش موجود في الكتالوج عندنا حاليًا."
                : 'الموديل الظاهر مش موجود في الكتالوج عندنا حاليًا.']],
            default => ['messages' => ['مش قادر أحدد الموديل من الصورة دي. ابعتلي صورة أوضح للاسم أو اللوجو.']],
        };
    }
}
