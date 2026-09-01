<?php

namespace Tests\Unit;

use App\Services\DocumentDataExtractor;
use PHPUnit\Framework\TestCase;

/**
 * "أهم حاجة تكون باسمه": a payslip, a pension statement, a licence or an
 * app screenshot proves nothing about this applicant if it belongs to
 * somebody else - and sending a friend's paperwork is the most common way
 * this flow gets gamed.
 *
 * These are the two rules that are NOT left to the extraction model's own
 * `valid` verdict, so they are tested directly: the model routinely
 * returns valid=true because the business rules it was handed never
 * mentioned the name.
 */
class DocumentOwnershipCheckTest extends TestCase
{
    private function check(array $outcome, string $documentType, array $context, string $ocrText = ''): array
    {
        $method = new \ReflectionMethod(DocumentDataExtractor::class, 'applyOwnershipChecks');
        $method->setAccessible(true);

        return $method->invoke(new DocumentDataExtractor(), $outcome, $documentType, $context, $ocrText);
    }

    /**
     * البطاقة المصرية مطبوع عليها الرقم بالهندي ومفرّق بمسافات، والعميل
     * بيكتبه في المحادثة بالعربي متصل. النصين دول نفس الرقم.
     */
    private const CARD_OCR = "جمهورية مصر العربية\nبطاقة تحقيق الشخصية\nكيرلس\nناجي فهيم سعد\n٢٩ ش الجمهورية\n٢ ٩ ٩ ٠ ١ ٠ ١ ٠ ١ ٠ ١ ٠ ١ ٥\nKG5657560";

    private const CARD_ID = '29901010101015';

    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'valid' => true,
            'fields' => [],
            'violation_message' => null,
            'name_on_document' => null,
            'name_matches' => null,
            'page_matches' => null,
            'page_seen' => null,
        ], $overrides);
    }

    /**
     * الباج اللي وقف العميل في الإنتاج: كان فيه رجوع مبكر قبل فحوصات
     * الملكية لو رد الموديل جه valid=false، فحارس الرقم القومي (اللي
     * بيلغي الرفض لما الرقم يكون فعلاً في البطاقة) عمره ما كان بيتنادى.
     */
    public function test_a_model_rejection_still_reaches_the_national_id_guard(): void
    {
        $result = $this->check(
            $this->outcome([
                'valid' => false,
                'name_matches' => true,
                'violation_message' => 'الرقم القومي في المستند غير مطابق للرقم القومي المسجل في الطلب.',
            ]),
            'id_card_front',
            ['expected_national_id' => self::CARD_ID],
            self::CARD_OCR
        );

        $this->assertTrue($result['valid']);
        $this->assertNull($result['violation_message']);
    }

    /**
     * ضهر البطاقة هو المرجع في الرقم القومي: على البطاقة الحقيقية اتقرا
     * الوش غلط مرتين من مرتين (الأرقام صغيرة وورا رسمة الأهرامات)
     * والضهر اتقرا صح، فالتسامح بخانة واحدة مسموح على الوش بس.
     */
    public function test_a_different_national_id_on_the_card_back_is_rejected_with_both_numbers(): void
    {
        $back = "۲۰۲۳/۱۱\n۲۸۸۱۲۱۲۰۲۰۲۰۲٤\nذكر مسلم أعزب\nالبطاقة سارية حتى ۲۰۳۰/۱۱/۲۰";

        $result = $this->check(
            $this->outcome(['valid' => true]),
            'id_card_back',
            ['expected_national_id' => self::CARD_ID],
            $back
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('28812120202024', $result['violation_message']);
        $this->assertStringContainsString(self::CARD_ID, $result['violation_message']);
    }

    public function test_a_matching_national_id_on_the_card_back_passes(): void
    {
        $back = "۲۰۲۳/۱۱\n۲۹۹۰۱۰۱۰۱۰۱۰۱۵\nذكر مسلم أعزب\nالبطاقة سارية حتى ۲۰۳۰/۱۱/۲۰";

        $result = $this->check(
            $this->outcome(['valid' => true]),
            'id_card_back',
            ['expected_national_id' => self::CARD_ID],
            $back
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * ولو الضهر مش مقروء: منقولش إن الرقم غلط (إحنا مقريناهوش أصلاً)
     * ومنعديهوش من غير تأكيد - بنطلب صورة أوضح.
     */
    public function test_an_unreadable_card_back_asks_for_a_clearer_photo(): void
    {
        $result = $this->check(
            $this->outcome(['valid' => true]),
            'id_card_back',
            ['expected_national_id' => self::CARD_ID],
            'ذكر مسلم أعزب البطاقة سارية'
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('صورة أوضح', $result['violation_message']);
        $this->assertStringNotContainsString('مش نفس الرقم المسجل', (string) $result['violation_message']);
    }

    /** ورقم مختلف فعلاً بيفضل مرفوض - الإصلاح في اتجاه واحد بس. */
    public function test_a_genuinely_different_national_id_is_still_rejected(): void
    {
        $result = $this->check(
            $this->outcome([
                'valid' => false,
                'violation_message' => 'الرقم القومي الموجود في المستند غير مطابق للرقم القومي المسجل في الطلب.',
            ]),
            'id_card_front',
            ['expected_national_id' => '28812120202024'],
            self::CARD_OCR
        );

        $this->assertFalse($result['valid']);
    }

    /** ومخالفة مالهاش علاقة بالرقم مبتتلغيش لمجرد إن الرقم مطابق. */
    public function test_an_unrelated_violation_survives_a_matching_national_id(): void
    {
        $result = $this->check(
            $this->outcome([
                'valid' => false,
                'violation_message' => 'صورة البطاقة مش واضحة، البيانات مقروءة بصعوبة.',
            ]),
            'id_card_front',
            ['expected_national_id' => self::CARD_ID],
            self::CARD_OCR
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('مش واضحة', $result['violation_message']);
    }

    /**
     * تطبيقات الشغل (طلبات/أوبر) بتكتب اسم المندوب بحروف لاتينية، والاسم
     * في المحادثة بيبقى عربي. المقارنة الحرفية مستحيل تطابقهم، فكان أي
     * سكرين بالإنجليزي بيترفض على إنه "باسم حد تاني" مهما كان صح.
     * المقارنة بقت على نطق الاسم: محمد و Mohamed/Mohammed/Muhamad نفس
     * الهيكل الساكن (mhmd).
     */
    public function test_the_same_name_written_in_english_is_the_same_person(): void
    {
        foreach ([
            'Mohamed Emam Mohamed Kamel _AL ALAMIA_BC',
            'Mohammed Imam Muhamad Kamil',
        ] as $nameOnDocument) {
            $result = $this->check(
                $this->outcome(['name_on_document' => $nameOnDocument, 'name_matches' => true]),
                'trips_screenshot',
                ['expected_name' => 'محمد امام محمد كامل']
            );

            $this->assertTrue($result['valid'], $nameOnDocument);
        }
    }

    /** ومستند باسم شخص تاني بالإنجليزي لسه بيترفض. */
    public function test_another_persons_english_name_is_still_rejected(): void
    {
        foreach ([
            'Sameh Mohamed Ibrahim Alshalqami',
            'Mahmoud Saeed Ali Hassan',
        ] as $nameOnDocument) {
            $result = $this->check(
                $this->outcome(['name_on_document' => $nameOnDocument, 'name_matches' => true]),
                'trips_screenshot',
                ['expected_name' => 'محمد امام محمد كامل']
            );

            $this->assertFalse($result['valid'], $nameOnDocument);
        }
    }

    public function test_document_in_another_persons_name_is_rejected(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => 'محمود سعيد علي', 'name_matches' => false]),
            'salary_slip',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('اسم تاني', $result['violation_message']);
    }

    /**
     * The floor under the model's judgement: if it said "same person"
     * while not one name part survives into the document, that is the
     * model being agreeable, not a match.
     */
    public function test_model_saying_match_does_not_override_a_totally_different_name(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => 'محمود سعيد علي', 'name_matches' => true]),
            'driver_license',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertFalse($result['valid']);
    }

    /**
     * A shorter or differently-spelled version of the same name is the
     * normal case, not fraud - a payslip carrying the triple name when the
     * chat carried the quadruple one must pass.
     */
    public function test_partial_name_match_is_accepted(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => 'كيرلس ناجي', 'name_matches' => true]),
            'salary_slip',
            ['expected_name' => 'كيرلس ناجي فهيم ميلاد']
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * OCR routinely mangles a letter; that must not read as a different
     * person.
     */
    public function test_single_letter_ocr_error_still_matches(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => 'كيرلص ناجي فهيم', 'name_matches' => true]),
            'trips_screenshot',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertTrue($result['valid']);
    }

    public function test_expired_licence_is_rejected(): void
    {
        $result = $this->check(
            $this->outcome([
                'fields' => ['is_expired' => true, 'expiry_date' => '2024-01-01'],
                'name_on_document' => 'كيرلس ناجي فهيم',
                'name_matches' => true,
            ]),
            'driver_license',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('منتهية', $result['violation_message']);
        $this->assertStringContainsString('2024-01-01', $result['violation_message']);
    }

    public function test_valid_licence_in_the_applicants_name_passes(): void
    {
        $result = $this->check(
            $this->outcome([
                'fields' => ['is_expired' => false, 'expiry_date' => '2029-01-01'],
                'name_on_document' => 'كيرلس ناجي فهيم',
                'name_matches' => true,
            ]),
            'driver_license',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * A photo of a shop carries no name, and the back of an Egyptian ID
     * has none either - enforcing a name match on those would reject every
     * genuine one.
     */
    public function test_documents_without_a_name_are_not_name_checked(): void
    {
        foreach (['activity_photo', 'id_card_back'] as $documentType) {
            $result = $this->check(
                $this->outcome(['name_matches' => false]),
                $documentType,
                ['expected_name' => 'كيرلس ناجي فهيم']
            );

            $this->assertTrue($result['valid'], $documentType);
        }
    }

    public function test_nothing_is_enforced_before_the_name_is_known(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => 'محمود سعيد', 'name_matches' => false]),
            'salary_slip',
            []
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * An unreadable name on the document is not evidence of fraud - the
     * model reports null and the document passes on its other merits.
     */
    public function test_unreadable_name_does_not_reject_the_document(): void
    {
        $result = $this->check(
            $this->outcome(['name_on_document' => null, 'name_matches' => null]),
            'trips_screenshot',
            ['expected_name' => 'كيرلس ناجي فهيم']
        );

        $this->assertTrue($result['valid']);
    }
}
