<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ApplicationStateService;
use App\Support\AddressParser;

/**
 * Missing-field detection moved from ApplicationHandler's private
 * methods into ApplicationStateService (deterministic, address-component
 * aware — see AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md Section 9/10).
 * These tests exercise that service directly instead of reflecting into
 * ApplicationHandler internals.
 */
class ApplicationHandlerTest extends TestCase
{
    private function service(): ApplicationStateService
    {
        return new ApplicationStateService(new AddressParser());
    }

    private function missingFields(array $data, bool $isFreelance = false): array
    {
        $service = $this->service();
        $data = $service->refreshAddressComponents($data);

        return $service->missingFields($data, $isFreelance);
    }

    /**
     * Case 1: home_address = "6 أكتوبر" only (no street/building).
     * Expected: home_address is considered missing.
     */
    public function test_home_address_incomplete_is_considered_missing(): void
    {
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'القاهرة شارع القصر العيني عمارة 10 الدور 2 شقة 5 بجوار مسجد النور',
            'home_address' => '6 أكتوبر',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data);

        $this->assertContains('home_address', $missing);
        $this->assertNotContains('work_address', $missing);
    }

    /**
     * Case 2: home_address has street + area + building.
     * Expected: home_address is considered complete.
     */
    public function test_home_address_complete_is_accepted(): void
    {
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'القاهرة شارع القصر العيني عمارة 10 الدور 2 شقة 5 بجوار مسجد النور',
            'home_address' => '6 أكتوبر الحي المتميز شارع 15 عمارة 4 الدور 3 شقة 7 ملك بجوار مسجد الفتح',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data);

        $this->assertEmpty($missing);
    }

    /**
     * Case 3: work_address = area only, no street/building.
     * Expected: work_address is considered missing.
     */
    public function test_work_address_incomplete_is_considered_missing(): void
    {
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'الجيزة',
            'home_address' => 'المهندسين شارع جامعة الدول عمارة 3 الدور 1 شقة 2 ملك بجوار نادي الشمس',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data);

        $this->assertContains('work_address', $missing);
        $this->assertNotContains('home_address', $missing);
    }

    /**
     * Reported bug: a delivery/gig worker with no fixed workplace
     * ("مليش مكان عمل") explicitly denies having one. The extraction layer
     * maps that to work_address = "لا يوجد" (ApplicationStateService::NO_WORKPLACE),
     * and this sentinel must be accepted as satisfied - not run through
     * address-component parsing, which would otherwise call it
     * "incomplete" forever since it has no street/building.
     */
    public function test_work_address_no_workplace_sentinel_is_satisfied(): void
    {
        $data = [
            'full_name' => 'كيرلس ناجي',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'مندوب توصيل/سواق تطبيقات',
            'income_proof' => 'لا يوجد',
            'work_address' => ApplicationStateService::NO_WORKPLACE,
            'home_address' => 'المهندسين شارع جامعة الدول عمارة 3 الدور 1 شقة 2 ملك بجوار نادي الشمس',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data, isFreelance: true);

        $this->assertNotContains('work_address', $missing);
    }

    /**
     * Case 4: work_address has street + area + building.
     * Expected: work_address is considered complete.
     */
    public function test_work_address_complete_is_accepted(): void
    {
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'مصر الجديدة شارع الميرغني عماره 10 الدور 4 شقة 8 بجوار سنترال مصر الجديدة',
            'home_address' => 'المهندسين شارع جامعة الدول عمارة 3 الدور 1 شقة 2 ملك بجوار نادي الشمس',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data);

        $this->assertEmpty($missing);
    }

    /**
     * Case 5: Ensure other fields are still validated normally.
     */
    public function test_other_fields_validated_normally(): void
    {
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '', // missing
            'phone' => '', // missing
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'مصر الجديدة شارع الميرغني عماره 10 الدور 4 شقة 8 بجوار سنترال مصر الجديدة',
            'home_address' => 'المهندسين شارع جامعة الدول عمارة 3 الدور 1 شقة 2 ملك بجوار نادي الشمس',
            'installment_months' => '', // missing
        ];

        $missing = $this->missingFields($data);

        $this->assertContains('national_id', $missing);
        $this->assertContains('phone', $missing);
        $this->assertContains('installment_months', $missing);
        $this->assertNotContains('home_address', $missing);
        $this->assertNotContains('work_address', $missing);
    }

    /**
     * Case 6: Freelance / craftsman (like سباك) does not require income_proof.
     */
    public function test_freelance_craftsman_does_not_require_income_proof(): void
    {
        $data = [
            'full_name' => 'احمد سيد حسين علي',
            'national_id' => '29011260101839',
            'phone' => '01200268302',
            'job_type' => 'سباك',
            'income_proof' => null,
            'work_address' => '6 أكتوبر الحي الأول شارع 10 عمارة 2 الدور 1 شقة 3 بجوار محطة بنزين',
            'home_address' => '6 أكتوبر الحي المتميز شارع 5 عمارة 5 الدور 2 شقة 6 ملك بجوار كنيسة العذراء',
            'installment_months' => 12,
        ];

        $missing = $this->missingFields($data, isFreelance: true);

        $this->assertNotContains('income_proof', $missing);
        $this->assertEmpty($missing);
    }

    /**
     * Test 1 from the improvement plan (extended): governorate/city/street
     * known, only building/floor/apartment are missing — must ask for
     * exactly those, not re-ask for the whole address. Business
     * requirement: an address is only complete with area + street +
     * building + floor + apartment all known.
     */
    public function test_partial_address_asks_only_for_missing_component(): void
    {
        $service = $this->service();
        $data = $service->refreshAddressComponents([
            'home_address' => 'شارع عباس العقاد، مدينة نصر، القاهرة',
        ]);

        $this->assertSame('incomplete', $data['home_address_status']);
        $this->assertSame(['building', 'floor', 'apartment', 'landmark', 'ownership'], $data['home_address_missing_components']);

        $question = $service->questionForMissing(['home_address'], $data);

        $this->assertStringContainsString('رقم العمارة', $question);
        $this->assertStringContainsString('الدور', $question);
        $this->assertStringContainsString('رقم الشقة', $question);
        $this->assertStringNotContainsString('بالتفصيل', $question);
    }

    /**
     * When the customer sends a follow-up that adds a new component (the
     * street) to an address that already had its area known, the reply
     * must acknowledge specifically what was just received ("حضرتك بعت
     * اسم الشارع") before listing what's still missing - not just repeat
     * the same missing-component list with no sign anything changed.
     */
    public function test_address_component_acknowledges_what_was_just_received(): void
    {
        $service = $this->service();

        // Turn 1: only the area is known.
        $data = $service->refreshAddressComponents([
            'home_address' => '6 أكتوبر',
        ]);

        // Turn 2: customer adds the street on top of the known area.
        $data['home_address'] = '6 أكتوبر شارع الحرية';
        $data = $service->refreshAddressComponents($data);

        $question = $service->questionForMissing(['home_address'], $data);

        $this->assertStringContainsString('استلمت منك اسم الشارع', $question);
        $this->assertStringContainsString('رقم العمارة', $question);
        $this->assertStringContainsString('الدور', $question);
        $this->assertStringContainsString('رقم الشقة', $question);
    }

    /**
     * Test 3 from the improvement plan: only a city name given ("٦
     * أكتوبر") — status is partial, not a full re-ask.
     */
    public function test_city_only_address_is_partial_not_fully_missing(): void
    {
        $service = $this->service();
        $data = $service->refreshAddressComponents([
            'home_address' => 'ساكن في 6 أكتوبر',
        ]);

        $this->assertSame('incomplete', $data['home_address_status']);
        $this->assertSame('6 أكتوبر', $data['home_address_components']['city']);
        $this->assertContains('street', $data['home_address_missing_components']);
        $this->assertContains('building', $data['home_address_missing_components']);
    }

    /**
     * Test 11 from the improvement plan: a sequentially different phone
     * number must be flagged as a conflict, never silently overwritten.
     */
    public function test_conflicting_phone_is_detected_not_silently_overwritten(): void
    {
        $service = $this->service();

        $known = ['phone' => '01234567890'];
        $extracted = ['phone' => '01111111111'];

        $conflicts = $service->detectConflicts($known, $extracted);

        $this->assertArrayHasKey('phone', $conflicts);
        $this->assertSame('01234567890', $conflicts['phone']['previous']);
        $this->assertSame('01111111111', $conflicts['phone']['new']);

        $question = $service->conflictQuestion($conflicts);
        $this->assertStringContainsString('01234567890', $question);
        $this->assertStringContainsString('01111111111', $question);
    }

    public function test_same_phone_resent_is_not_a_conflict(): void
    {
        $service = $this->service();

        $conflicts = $service->detectConflicts(
            ['phone' => '01234567890'],
            ['phone' => '01234567890']
        );

        $this->assertEmpty($conflicts);
    }

    public function test_conflict_resolves_when_customer_says_new(): void
    {
        $service = $this->service();
        $conflicts = $service->detectConflicts(
            ['phone' => '01234567890'],
            ['phone' => '01111111111']
        );

        $resolved = $service->resolveConflicts($conflicts, 'اعتمد الرقم الجديد');

        $this->assertSame('01111111111', $resolved['phone']);
    }

    public function test_conflict_resolves_when_customer_resends_a_value(): void
    {
        $service = $this->service();
        $conflicts = $service->detectConflicts(
            ['phone' => '01234567890'],
            ['phone' => '01111111111']
        );

        $resolved = $service->resolveConflicts($conflicts, '01234567890');

        $this->assertSame('01234567890', $resolved['phone']);
    }

    /**
     * Reported bug: both work_address and home_address start fully empty.
     * The customer answers with one ambiguous line ("٦ أكتوبر") that
     * extraction lands on only one of them (work_address here, matching
     * the real extractApplicationData order-based heuristic). The
     * still-missing home_address must not hide the fact that
     * work_address is now partially known - each line in the list must
     * name its own state, not fall back to one generic template for
     * every remaining field.
     */
    public function test_multiple_missing_fields_show_per_field_partial_address_detail(): void
    {
        $service = $this->service();

        $application = $service->refreshAddressComponents([
            'work_address' => '٦ اكتوبر',
        ]);

        $missing = ['work_address', 'home_address', 'installment_months'];

        $question = $service->questionForMissing($missing, $application, []);

        $this->assertStringContainsString('عنوان الشغل', $question);
        $this->assertStringContainsString('رقم العمارة', $question);
        $this->assertStringContainsString('عنوان السكن بالتفصيل', $question);
    }

    /**
     * Reported bug: work_address was already partially known ("٦
     * أكتوبر", status=incomplete) and home_address was still fully
     * empty. The extraction model then put a NEW address-shaped follow
     * up ("عماره 4 الدور 2") into home_address instead of continuing
     * work_address, because it only sees "home_address is null" and has
     * no concept of "work_address already has something but isn't done
     * yet". reconcileAddressAssignment() must redirect that back to
     * work_address deterministically.
     */
    public function test_address_continuation_is_not_misrouted_to_the_other_field(): void
    {
        $service = $this->service();

        $application = $service->refreshAddressComponents([
            'work_address' => '٦ اكتوبر',
        ]);

        // Model incorrectly assigned the follow-up to home_address.
        $extracted = [
            'home_address' => 'عماره 4 الدور 2',
            'home_address_status' => 'incomplete',
        ];

        $reconciled = $service->reconcileAddressAssignment($application, $extracted);

        $this->assertSame('عماره 4 الدور 2', $reconciled['work_address']);
        $this->assertNull($reconciled['home_address']);
    }

    public function test_address_reconciliation_leaves_a_genuine_second_field_alone(): void
    {
        $service = $this->service();

        // work_address is already COMPLETE, not incomplete - a new
        // home_address value here is legitimate and must not be touched.
        $application = $service->refreshAddressComponents([
            'work_address' => 'الجيزة شارع الهرم عمارة 1 الدور 1 شقة 1 بجوار الاهرامات',
        ]);

        $extracted = ['home_address' => '٦ اكتوبر'];

        $reconciled = $service->reconcileAddressAssignment($application, $extracted);

        $this->assertSame('٦ اكتوبر', $reconciled['home_address']);
        $this->assertArrayNotHasKey('work_address', $reconciled);
    }

    /**
     * Reported bug: the conflict question is phrased as "بعت X قبل كده
     * وبعدين بعت Y", so a customer answering with an ordinal ("التاني",
     * "الأخير") rather than the literal words "قديم"/"جديد" must resolve
     * exactly the same way - the second/last-mentioned value is always
     * the newer one.
     */
    public function test_conflict_resolves_via_ordinal_reference(): void
    {
        $service = $this->service();
        $conflicts = $service->detectConflicts(
            ['phone' => '01200268302'],
            ['phone' => '012033333']
        );

        $this->assertSame('012033333', $service->resolveConflicts($conflicts, 'التاني')['phone']);
        $this->assertSame('012033333', $service->resolveConflicts($conflicts, 'الاخير')['phone']);
        $this->assertSame('012033333', $service->resolveConflicts($conflicts, 'آخر واحد')['phone']);
        $this->assertSame('01200268302', $service->resolveConflicts($conflicts, 'الاول')['phone']);
    }
}
