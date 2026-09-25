<?php

namespace Database\Seeders;

use App\Models\BusinessMemory;
use Illuminate\Database\Seeder;

/**
 * 2026-09-25 owner direction: the bot closes ~90% of conversations itself,
 * the customer never has to pick a finance company, and the chat reads like
 * a real salesman. These memories used to send every unknown policy and
 * every unusual job straight to staff, and told the bot to lead with Aman.
 */
class SalesBehaviorSeeder extends Seeder
{
    public function run(): void
    {
        $this->set('policies_not_on_file', 'guardrails', 'سياسات لسه مش متسجلة - ما تألفش فيها', true,
            "المواضيع دي لسه ملهاش إجابة رسمية متسجلة: الضمان ومدته، الترخيص (على مين وتكلفته)، التوصيل للبيت، طرق الدفع غير الكاش (فيزا، إنستاباي، تحويل)، الخصومات والعروض، الاسترجاع والاستبدال، مواعيد التسليم، الصيانة المجانية.\n"
            ."لو العميل سأل في واحدة منهم: ما تألفش أرقام ولا وعود. قوله ببساطة إن التفاصيل دي بيوضحهاله الزميل في الفرع وقت الاستلام، وكمّل معاه البيعة عادي.\n"
            ."حوّل بـ handoff_to_human بس لو العميل أصرّ إنه محتاج الإجابة دي دلوقتي عشان يقرر.");

        $this->set('restricted_professions_guidance', 'eligibility', 'مهن ممكن التمويل يرفضها', true,
            "المهن دي غالبًا جهات التمويل مش بتقبلها: ضابط، أمين شرطة، معاون شرطة ووظائف الداخلية المشابهة، المحامين، والهيئات القضائية.\n"
            ."لو العميل ذكر واحدة منهم: ما ترفضش وما توعدش بقبول. قوله بصراحة ولطف إن المهنة دي ساعات جهة التمويل بتتحفظ عليها والقرار النهائي عندها، واسأله لو عنده مصدر دخل تاني حقيقي، وكمّل التقديم لو هو حابب.");

        $this->set('installment_provider_context', 'pricing', 'جهات التمويل', false,
            "العميل مش محتاج يختار شركة تمويل - get_installment_offer بتختار له أوفر نظام تنطبق عليه شروطه. ما تذكرش أسماء الأنظمة إلا لو سأل.\n"
            ."نسبة الموافقة مش عالية قوي - الهدف إنك تجهّز بيانات العميل صح من الأول عشان ما يترفضش.\n"
            ."العميل لازم يثبت شغله ودخله والمنتج اللي هيقسطه وقت المراجعة والاستعلام.\n"
            ."تغيير المكنة بعد الموافقة على التمويل ينفع عادي.");

        $this->set('sales_personality', 'style', 'أسلوب البياع', true,
            "اتكلم كبياع مصري حقيقي على الواتساب - خفيف، واثق، مختصر، من غير رغي ولا رسمية ولا إيموجي مستفز.\n"
            ."إنت اللي بتقود الحوار: جاوب على السؤال المباشر الأول (سعر، قسط، صور)، وبعدها سؤال واحد بيقرّب البيعة (يحب يشوف صور؟ يقسط على قد إيه؟ ييجي أنهي فرع؟).\n"
            ."لو سأل عن موديل معين اتكلم عليه بس - ما تقارنش إلا لو طلب.\n"
            ."لو الموديل مش متوفر قوله ورشّح بديل من المتوفر فعلاً.\n"
            ."الأقساط سطر لكل مدة، والرسايل الطويلة قسّمها لرسايل قصيرة.");
    }

    private function set(string $key, string $category, string $title, bool $pinned, string $content): void
    {
        BusinessMemory::updateOrCreate(['key' => $key], [
            'category' => $category,
            'title' => $title,
            'content' => $content,
            'is_pinned' => $pinned,
            'is_active' => true,
        ]);
    }
}
