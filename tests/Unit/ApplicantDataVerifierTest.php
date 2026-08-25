<?php

namespace Tests\Unit;

use App\Services\AddressPlausibilityValidator;
use App\Services\ApplicantDataVerifier;
use App\Services\ApplicantNameValidator;
use App\Support\AddressParser;
use App\Support\EgyptianNationalId;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The verifier is the gate that stops a well-formed but meaningless
 * application from reaching staff: a two-word name, a national ID that
 * decodes to nothing, or a street that is a song lyric.
 *
 * Both validators are stubbed here - what is under test is the wiring
 * (which field gets cleared, which issue is reported, when the escape
 * hatch opens), not the model prompts.
 */
class ApplicantDataVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 8, 26));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function verifier(string $nameStatus = 'ok', string $addressVerdict = 'real'): ApplicantDataVerifier
    {
        $nameValidator = new class($nameStatus) extends ApplicantNameValidator {
            public function __construct(private string $status)
            {
            }

            public function validate(?string $raw): array
            {
                return [
                    'status' => $this->status,
                    'name' => trim((string) $raw),
                    'parts' => [],
                    'reason' => $this->status === 'ok' ? null : 'stub',
                    'message' => $this->status === 'ok' ? null : 'محتاج الاسم بالكامل زي ما هو في البطاقة.',
                ];
            }
        };

        $addressValidator = new class($addressVerdict) extends AddressPlausibilityValidator {
            public function __construct(private string $verdict)
            {
            }

            public function validate(string $text, string $field, array $components = []): array
            {
                return [
                    'verdict' => $this->verdict,
                    'confidence' => 0.9,
                    'reason' => 'stub',
                    'question' => $this->verdict === 'real' ? null : 'وضّحلي العنوان أكتر من فضلك.',
                    'suspect_part' => null,
                    'checked' => true,
                ];
            }
        };

        return new ApplicantDataVerifier(
            $nameValidator,
            $addressValidator,
            new EgyptianNationalId(),
            new AddressParser(),
        );
    }

    private function passingAddressValidator(): AddressPlausibilityValidator
    {
        return new class extends AddressPlausibilityValidator {
            public function __construct()
            {
            }

            public function validate(string $text, string $field, array $components = []): array
            {
                return [
                    'verdict' => 'real',
                    'confidence' => 1.0,
                    'reason' => null,
                    'question' => null,
                    'suspect_part' => null,
                    'checked' => true,
                ];
            }
        };
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'كيرلس ناجي فهيم',
            'national_id' => '29005132101234',
            'home_address' => 'الجيزة شارع الهرم عمارة 5',
        ], $overrides);
    }

    public function test_valid_data_passes_with_birthdate_derived(): void
    {
        $result = $this->verifier()->verify($this->application());

        $this->assertSame([], $result['issues']);
        $this->assertNull($result['blocking_message']);
        $this->assertSame('1990-05-13', $result['application']['birthdate']);
        $this->assertTrue($result['application']['age_ok']);
    }

    public function test_national_id_is_normalized_to_bare_digits(): void
    {
        $result = $this->verifier()->verify($this->application([
            'national_id' => '2900 5132 1012 34',
        ]));

        $this->assertSame('29005132101234', $result['application']['national_id']);
    }

    /**
     * Age outside the financing window is the same kind of answer as an
     * excluded profession - nothing later in the flow can change it, so it
     * stops the turn instead of joining the missing-fields list.
     */
    public function test_underage_applicant_blocks_the_turn(): void
    {
        $result = $this->verifier()->verify($this->application([
            'national_id' => '31003152101234', // born 2010
        ]));

        $this->assertNotNull($result['blocking_message']);
        $this->assertSame([], $result['issues']);
    }

    public function test_undecodable_national_id_is_cleared_and_reported(): void
    {
        $result = $this->verifier()->verify($this->application([
            'national_id' => '29013132101234', // month 13
        ]));

        $this->assertNull($result['application']['national_id']);
        $this->assertArrayHasKey('national_id', $result['issues']);
    }

    public function test_rejected_name_is_cleared_so_it_reads_as_missing(): void
    {
        $result = $this->verifier('incomplete')->verify($this->application([
            'full_name' => 'أحمد محمد',
        ]));

        $this->assertNull($result['application']['full_name']);
        $this->assertSame('أحمد محمد', $result['application']['full_name_rejected']);
        $this->assertArrayHasKey('full_name', $result['issues']);
    }

    /**
     * The check must never trap someone. After MAX_ATTEMPTS the value is
     * accepted and flagged for a human instead of being asked for forever.
     */
    public function test_name_is_accepted_for_review_after_max_attempts(): void
    {
        $verifier = $this->verifier('incomplete');
        $application = $this->application(['full_name' => 'أحمد محمد']);

        for ($i = 0; $i <= ApplicantDataVerifier::MAX_ATTEMPTS; $i++) {
            $result = $verifier->verify($application);
            $application = $result['application'];
            // Customer re-sends the same name each turn.
            $application['full_name'] = 'أحمد محمد';
        }

        $this->assertSame('أحمد محمد', $result['application']['full_name']);
        $this->assertTrue($result['application']['full_name_needs_review']);
        $this->assertSame([], $result['issues']);
    }

    /**
     * Re-sending the exact same value still costs the customer a turn (so
     * the escape hatch above can eventually open) but must not cost
     * another model call - the verdict on an identical string cannot
     * change, and re-asking would be pure spend.
     */
    public function test_resent_value_counts_as_an_attempt_without_a_second_model_call(): void
    {
        $calls = 0;

        $nameValidator = new class($calls) extends ApplicantNameValidator {
            public function __construct(private int &$calls)
            {
            }

            public function validate(?string $raw): array
            {
                $this->calls++;

                return [
                    'status' => 'incomplete',
                    'name' => trim((string) $raw),
                    'parts' => [],
                    'reason' => 'too_few_parts',
                    'message' => 'محتاج الاسم بالكامل زي ما هو في البطاقة.',
                ];
            }
        };

        $verifier = new ApplicantDataVerifier(
            $nameValidator,
            $this->passingAddressValidator(),
            new EgyptianNationalId(),
            new AddressParser(),
        );

        $first = $verifier->verify($this->application(['full_name' => 'أحمد محمد']));

        // The customer sends the identical name again next turn.
        $application = $first['application'];
        $application['full_name'] = 'أحمد محمد';

        $second = $verifier->verify($application);

        $this->assertSame(1, $calls);
        $this->assertSame(2, $second['application']['full_name_attempts']);
        $this->assertArrayHasKey('full_name', $second['issues']);
        $this->assertNull($second['application']['full_name']);
    }

    public function test_fake_address_is_cleared_and_reported(): void
    {
        $result = $this->verifier('ok', 'fake')->verify($this->application([
            'home_address' => '١٢ شارع ياوحشني سلامات',
        ]));

        $this->assertNull($result['application']['home_address']);
        $this->assertArrayHasKey('home_address', $result['issues']);
    }

    /**
     * "unclear" is not a rejection - the address is kept and the flow's
     * own landmark/area questions do the clarifying. Only the review flag
     * is raised.
     */
    public function test_unclear_address_is_kept_but_flagged_for_review(): void
    {
        $result = $this->verifier('ok', 'unclear')->verify($this->application([
            'home_address' => 'عزبة الوالدة',
        ]));

        $this->assertSame('عزبة الوالدة', $result['application']['home_address']);
        $this->assertTrue($result['application']['home_address_needs_review']);
        $this->assertSame([], $result['issues']);
    }

    public function test_no_workplace_sentinel_is_not_address_checked(): void
    {
        $result = $this->verifier('ok', 'fake')->verify($this->application([
            'work_address' => 'لا يوجد',
        ]));

        $this->assertSame('لا يوجد', $result['application']['work_address']);
        $this->assertArrayNotHasKey('work_address', $result['issues']);
    }

    public function test_empty_fields_produce_no_issues(): void
    {
        $result = $this->verifier('incomplete', 'fake')->verify([]);

        $this->assertSame([], $result['issues']);
    }
}
