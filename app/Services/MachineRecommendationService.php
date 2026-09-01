<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Collection;

/**
 * ترشيح موديلات من الكتالوج الحقيقي.
 *
 * المشكلة اللي بيحلها: البوت كان **مش بيرشّح خالص**. العميل يقول "عايز
 * موتوسيكل للشغل، أي حاجة كويسة" والبوت يرد "إيه الموديل اللي بتفكر
 * فيه؟"، ويقولها تاني وتالت. في تجربة حقيقية العميل طلب ترشيح ٣ مرات
 * ورا بعض ومخدش ولا اسم موديل واحد - وده أكتر سؤال بيتسأل في المعرض
 * أصلاً.
 *
 * ليه deterministic ومش متروك للـ LLM: الترشيح لازم يبقى بأسماء وأسعار
 * حقيقية من الداتابيز. أي ترشيح من دماغ الموديل معناه اسم مكنة مش
 * موجودة أو سعر غلط بيوصل للعميل كأنه رسمي - وده بالظبط اللي البرومبت
 * مانعه في كل حتة تانية.
 */
class MachineRecommendationService
{
    /** أقصى عدد موديلات في رسالة واحدة - أكتر من كده بيتحوّل لكتالوج. */
    private const MAX_SUGGESTIONS = 3;

    /**
     * أرخص سعر عندنا، عشان لما ميزانية العميل تحت السوق كله نقوله رقم
     * حقيقي بدل ما نقول "مفيش" وخلاص.
     */
    public function cheapestPrice(): ?float
    {
        $price = Machine::query()->whereNotNull('cash_price')->min('cash_price');

        return $price !== null ? (float) $price : null;
    }

    /**
     * بيبني رسالة ترشيح جاهزة.
     *
     * @param  float|null  $budget  سقف السعر اللي العميل قاله، لو قال
     * @return array{reply: string, machines: Collection}
     */
    public function recommend(
        WhatsappConversation $conversation,
        string $message,
        ?float $budget = null
    ): array {
        $usage = $this->detectUsage($conversation, $message);

        $candidates = $this->candidates($budget);

        if ($candidates->isEmpty()) {
            return [
                'reply' => $this->nothingInBudgetReply($budget),
                'machines' => collect(),
            ];
        }

        $picked = $this->pick($candidates, $usage, $budget);

        $lines = $picked->map(function (Machine $machine) use ($usage) {
            $price = $machine->cash_price !== null
                ? number_format((float) $machine->cash_price) . ' جنيه'
                : 'السعر محتاج تأكيد';

            $why = $this->whyLine($machine, $usage);

            return "- {$this->displayName($machine)}: {$price}" . ($why !== '' ? " - {$why}" : '');
        })->implode("\n");

        $intro = match (true) {
            $budget !== null => 'في حدود ' . number_format($budget) . ' جنيه، دي أنسب حاجة عندنا:',
            $usage === 'work' => 'لشغل التوصيل، دي أنسب تلات موديلات عندنا:',
            default => 'دي أكتر تلات موديلات بتمشي عندنا:',
        };

        $tail = $usage === null
            ? "\n\nهي هتكون لشغل ولا استخدام شخصي؟ أظبطلك الترشيح أكتر."
            : "\n\nتحب أبعتلك صور أي واحدة فيهم؟";

        return [
            'reply' => "{$intro}\n{$lines}{$tail}",
            'machines' => $picked,
        ];
    }

    /**
     * الميزانية أقل من أرخص حاجة عندنا. الرد لازم يقول الرقم الحقيقي -
     * "مفيش في الميزانية دي" لوحدها بتنهي المحادثة، بينما الرقم بيدي
     * العميل قرار.
     */
    private function nothingInBudgetReply(?float $budget): string
    {
        $cheapest = $this->cheapestPrice();

        if ($budget !== null && $cheapest !== null) {
            return 'معلش يا فندم، مفيش عندنا حاجة في حدود ' . number_format($budget) . ' جنيه كاش.'
                . "\nأرخص موديل عندنا بـ " . number_format($cheapest) . ' جنيه.'
                . "\nبس بالتقسيط المقدم بيبقى أقل من كده بكتير - تحب أحسبهالك؟";
        }

        return 'قولي يا فندم هي للشغل ولا استخدام شخصي، وأنا أرشحلك الأنسب.';
    }

    /**
     * الموديلات المرشحة للترشيح: ليها سعر كاش، وجوه الميزانية لو فيه
     * ميزانية.
     */
    private function candidates(?float $budget): Collection
    {
        return Machine::query()
            ->with('brand')
            ->whereNotNull('cash_price')
            ->where('cash_price', '>', 0)
            ->when($budget !== null, fn ($q) => $q->where('cash_price', '<=', $budget))
            ->orderBy('cash_price')
            ->get();
    }

    /**
     * الاختيار النهائي. مع ميزانية: بنبدأ من الأعلى سعرًا جوه الميزانية
     * (أقرب حاجة لسقفه = أحسن حاجة يقدر ياخدها). من غير ميزانية: بنعرض
     * مدى - رخيص ومتوسط وأعلى - عشان العميل يعرف نفسه فين.
     */
    private function pick(Collection $candidates, ?string $usage, ?float $budget): Collection
    {
        if ($budget !== null) {
            return $candidates->sortByDesc('cash_price')->take(self::MAX_SUGGESTIONS)->values();
        }

        $sorted = $candidates->values();
        $count = $sorted->count();

        if ($count <= self::MAX_SUGGESTIONS) {
            return $sorted;
        }

        return collect([
            $sorted->get((int) floor($count * 0.15)),
            $sorted->get((int) floor($count * 0.45)),
            $sorted->get((int) floor($count * 0.75)),
        ])->filter()->unique('id')->values();
    }

    /**
     * سطر "ليه دي مناسبة". بيتبني من بيانات المكنة نفسها بس - مفيش أي
     * ادعاء عن استهلاك بنزين أو متانة أو ضمان، لأن دي معلومات مش موجودة
     * في الداتابيز والاختراع فيها بيتحوّل لالتزام على المعرض.
     */
    private function whyLine(Machine $machine, ?string $usage): string
    {
        $price = (float) ($machine->cash_price ?? 0);
        $cheapest = $this->cheapestPrice();

        /*
         * "أقل سعر عندنا" لازم تبقى صح حرفيًا. كانت بهامش ١٥٪ فطلعت على
         * مكنة بـ٤٠ ألف والأرخص فعلًا ٣٥ - جملة صغيرة بس غلط، والعميل
         * بيبني عليها قرار.
         */
        if ($cheapest !== null && $price <= $cheapest) {
            return 'أقل سعر عندنا';
        }

        if ($usage === 'work') {
            return 'مطلوبة كتير في شغل التوصيل';
        }

        return '';
    }

    /**
     * الاستخدام: شغل ولا شخصي. بيتقرا من الرسالة الحالية وكمان من
     * وظيفة العميل لو اتسجلت في الطلب - مش منطقي نسأل مندوب توصيل قال
     * لنا شغله من ٣ رسايل "هي للشغل ولا شخصي؟".
     */
    private function detectUsage(WhatsappConversation $conversation, string $message): ?string
    {
        $text = ' ' . $message . ' ';

        $found = null;

        foreach (['شغل', 'توصيل', 'طلبات', 'دليفري', 'اوردرات', 'أوردرات', 'مشاوير', 'رزق'] as $word) {
            if (str_contains($text, $word)) {
                $found = 'work';
                break;
            }
        }

        if ($found === null) {
            foreach (['شخصي', 'استخدام شخصي', 'لنفسي', 'للفسح', 'مشوار شخصي'] as $word) {
                if (str_contains($text, $word)) {
                    $found = 'personal';
                    break;
                }
            }
        }

        $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];

        if ($found === null) {
            $jobType = trim((string) ($context['application']['job_type'] ?? ''));

            if ($jobType !== '' && str_contains($jobType, 'مندوب')) {
                $found = 'work';
            }
        }

        /*
         * العميل قال الاستخدام مرة، خلاص. من غير الحفظ ده كان بيتسأل
         * "هي للشغل ولا شخصي؟" في كل ترشيح جديد رغم إنه قالها في أول
         * رسالة - وده بالظبط الإحساس بإن البوت مش قاري كلامه.
         */
        if ($found === null) {
            $remembered = $context['recommendation_usage'] ?? null;

            return in_array($remembered, ['work', 'personal'], true) ? $remembered : null;
        }

        if (($context['recommendation_usage'] ?? null) !== $found) {
            $context['recommendation_usage'] = $found;
            $conversation->forceFill(['context_payload' => $context])->save();
        }

        return $found;
    }

    private function displayName(Machine $machine): string
    {
        $brand = trim((string) ($machine->brand?->name ?? ''));
        $name = trim((string) $machine->name);

        if ($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
            return $brand . ' ' . $name;
        }

        return $name;
    }
}
