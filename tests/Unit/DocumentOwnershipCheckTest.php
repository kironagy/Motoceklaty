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
    private function check(array $outcome, string $documentType, array $context): array
    {
        $method = new \ReflectionMethod(DocumentDataExtractor::class, 'applyOwnershipChecks');
        $method->setAccessible(true);

        return $method->invoke(new DocumentDataExtractor(), $outcome, $documentType, $context);
    }

    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'valid' => true,
            'fields' => [],
            'violation_message' => null,
            'name_on_document' => null,
            'name_matches' => null,
        ], $overrides);
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
