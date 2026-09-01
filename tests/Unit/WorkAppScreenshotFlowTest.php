<?php

namespace Tests\Unit;

use App\Services\Handlers\ApplicationHandler;
use PHPUnit\Framework\TestCase;

/**
 * المطلوب من سواق التطبيقات **بيانات** مش عدد صور:
 *
 *   1) تاريخ التعيين/الانضمام للتطبيق
 *   2) الملف التعريفي اللي فيه اسمه (عشان نتأكد إن الحساب بتاعه)
 *   3) دخل آخر 3 شهور (عشان نتأكد إنه شغال فعلاً والحساب مش فاضي)
 *
 * لو سكرين واحد فيه التلاتة، خلاص. ولو متفرقين، بيتجمعوا من كذا سكرين.
 * الرخصة منفصلة تمامًا وبتتحدد بالمركبة لوحدها - العجلة مالهاش رخصة.
 */
class WorkAppScreenshotFlowTest extends TestCase
{
    private function requiredDocuments(array $application): array
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'requiredDocuments');
        $method->setAccessible(true);

        return $method->invoke(new ApplicationHandler(), $application);
    }

    private function missingFacts(array $facts): array
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'missingWorkAppFacts');
        $method->setAccessible(true);

        return $method->invoke(new ApplicationHandler(), $facts);
    }

    private function merge(array $facts, array $incoming): array
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'mergeWorkAppFacts');
        $method->setAccessible(true);

        return $method->invoke(new ApplicationHandler(), $facts, $incoming);
    }

    private function label(string $docType): string
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'documentLabel');
        $method->setAccessible(true);

        return $method->invoke(new ApplicationHandler(), $docType);
    }

    private function completeFacts(): array
    {
        return [
            'hiring_date' => '2025-10-09',
            'account_name' => 'Mohamed Emam Mohamed Kamel',
            'account_active' => true,
            'income_months' => ['2026-06' => 8000.0, '2026-07' => 9200.0, '2026-08' => 8700.0],
        ];
    }

    public function test_a_bicycle_courier_is_asked_for_the_app_data_and_no_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'مندوب توصيل/سواق تطبيقات',
            'work_vehicle' => 'bicycle',
        ]);

        $this->assertSame(['id_card_front', 'id_card_back', 'work_app_screens'], $documents);
        $this->assertNotContains('driver_license', $documents);
    }

    public function test_a_motorcycle_courier_is_asked_for_the_same_data_plus_a_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'شغال طلبات',
            'work_vehicle' => 'motorcycle',
        ]);

        $this->assertSame(['id_card_front', 'id_card_back', 'work_app_screens', 'driver_license'], $documents);
    }

    public function test_the_ask_names_the_three_things_and_allows_one_screenshot(): void
    {
        $ask = $this->label('work_app_screens');

        $this->assertStringContainsString('تاريخ التعيين', $ask);
        $this->assertStringContainsString('اسم حضرتك', $ask);
        $this->assertStringContainsString('آخر 3 شهور', $ask);
        $this->assertStringContainsString('سكرين واحد', $ask);
    }

    public function test_nothing_collected_yet_means_all_three_are_missing(): void
    {
        $this->assertSame(['hiring_date', 'account_name', 'income'], $this->missingFacts([]));
    }

    /** سكرين واحد فيه التلاتة بيقفل الخطوة - مش لازم 3 صور. */
    public function test_one_screenshot_carrying_everything_completes_the_step(): void
    {
        $this->assertSame([], $this->missingFacts($this->completeFacts()));
    }

    public function test_a_profile_screenshot_alone_still_leaves_the_income_missing(): void
    {
        $facts = $this->merge([], [
            'account_name' => 'Mohamed Emam Mohamed Kamel',
            'hiring_date' => '2025-10-09',
            'income_periods' => [],
        ]);

        $this->assertSame(['income'], $this->missingFacts($facts));
    }

    /** حساب فاضي مش إثبات دخل مهما كانت الشهور ظاهرة. */
    public function test_an_empty_account_is_not_income(): void
    {
        $facts = $this->completeFacts();
        $facts['account_active'] = false;

        $this->assertContains('income', $this->missingFacts($facts));
    }

    public function test_income_from_fewer_than_three_months_is_not_enough(): void
    {
        $facts = $this->merge([], [
            'hiring_date' => '2025-10-09',
            'account_name' => 'Mohamed',
            'account_active' => true,
            'income_periods' => [
                ['label' => 'الاثنين 10 أغسطس', 'month' => '2026-08', 'amount' => 2332.01],
                ['label' => 'الاثنين 17 أغسطس', 'month' => '2026-08', 'amount' => 2731.07],
            ],
        ]);

        // أسبوعين من نفس الشهر = شهر واحد، مش شهرين.
        $this->assertSame(['2026-08'], array_keys($facts['income_months']));
        $this->assertEqualsWithDelta(5063.08, $facts['income_months']['2026-08'], 0.01);
        $this->assertContains('income', $this->missingFacts($facts));
    }

    /** والبيانات بتتراكم من سكرين لسكرين لحد ما تكتمل. */
    public function test_facts_accumulate_across_screenshots(): void
    {
        $facts = $this->merge([], ['account_name' => 'Mohamed Emam', 'income_periods' => []]);
        $facts = $this->merge($facts, ['hiring_date_text' => 'إنضم يوليو 2026', 'income_periods' => []]);

        $this->assertSame(['income'], $this->missingFacts($facts));

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            $facts = $this->merge($facts, [
                'account_active' => true,
                'income_periods' => [['label' => $month, 'month' => $month, 'amount' => 8000]],
            ]);
        }

        $this->assertSame([], $this->missingFacts($facts));
    }

    /** الصفر مش دخل - المبالغ اللي بصفر مش بتتعد شهر. */
    public function test_zero_amounts_do_not_count_as_a_month(): void
    {
        $facts = $this->merge([], [
            'income_periods' => [['label' => 'أغسطس', 'month' => '2026-08', 'amount' => 0]],
        ]);

        $this->assertSame([], $facts['income_months']);
    }
}
