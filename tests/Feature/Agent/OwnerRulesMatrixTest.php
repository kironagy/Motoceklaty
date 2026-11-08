<?php

namespace Tests\Feature\Agent;

use App\Domain\Applications\WorkClassification;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rescue 2026-11-08: the owner's job rules are decided on the APPLICANT's own
 * current job as the model read it - never by matching words in the message.
 * "اخويا محامي" used to refuse the customer himself, and "امين مخازن مدني في
 * الشرطه" was refused as a police amin.
 */
class OwnerRulesMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function problemFor(array $profile, string $type = 'employee'): ?string
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'r'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '20100'.random_int(100000, 999999), 'status' => 'open',
            'state' => ['work_profile' => $profile + ['work_stated' => true, 'applicant' => 'customer', 'working_now' => 'yes',
                'customer_type' => $type, 'insured' => 'yes', 'sector' => 'private', 'relation_to_workplace' => 'works_for_someone']]]);

        return app(WorkClassification::class)->problem($conversation->id, $type)['code'] ?? null;
    }

    public static function cases(): array
    {
        return [
            // his brother is the lawyer; he is a private-company accountant
            'relative is a lawyer' => [['evidence' => 'اخويا محامي وانا محاسب في شركة', 'occupation' => 'محاسب', 'occupation_category' => 'other'], 'employee', null],
            'civilian in police' => [['evidence' => 'امين مخازن مدني في الشرطه', 'occupation' => 'امين مخازن', 'occupation_category' => 'civilian_in_police_or_army'], 'employee', null],
            'police amin' => [['evidence' => 'انا امين شرطه', 'occupation' => 'امين شرطة', 'occupation_category' => 'police_amin'], 'employee', 'OCCUPATION_NOT_ACCEPTED'],
            'police officer' => [['evidence' => 'نقيب شرطه', 'occupation' => 'ضابط شرطة', 'occupation_category' => 'police_officer'], 'employee', 'OCCUPATION_NOT_ACCEPTED'],
            'army officer applies' => [['evidence' => 'رائد في الجيش', 'occupation' => 'ضابط جيش', 'occupation_category' => 'army_officer'], 'employee', null],
            'lawyer himself' => [['evidence' => 'انا محامي', 'occupation' => 'محامي', 'occupation_category' => 'lawyer'], 'employee', 'OCCUPATION_NOT_ACCEPTED'],
            'judge' => [['evidence' => 'وكيل نيابة', 'occupation' => 'وكيل نيابة', 'occupation_category' => 'judge_or_prosecutor'], 'employee', 'OCCUPATION_NOT_ACCEPTED'],
            // owner 2026-10-04: any pensioner is accepted whatever his former job
            'pensioner former police' => [['evidence' => 'معاش شرطه', 'occupation' => 'معاش', 'occupation_category' => 'police_officer', 'working_now' => 'no'], 'pension', null],
            // owner 2026-10-03: no installments for foreigners at all
            'foreigner' => [['evidence' => 'انا سوري شغال في مطعم', 'occupation' => 'عامل مطعم', 'occupation_category' => 'other', 'applicant_nationality' => 'foreign'], 'employee', 'FOREIGNER_NO_INSTALLMENTS'],
            // government work alone is not refused (owner policy 2026-10-30)
            'government teacher' => [['evidence' => 'مدرس حكومي', 'occupation' => 'مدرس', 'occupation_category' => 'other', 'sector' => 'government'], 'employee', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_owner_job_rules_read_the_applicants_own_job(array $profile, string $type, ?string $expected): void
    {
        $this->assertSame($expected, $this->problemFor($profile, $type));
    }

    public function test_a_woman_on_free_income_is_refused(): void
    {
        $code = $this->problemFor(['evidence' => 'بشتغل خياطة من البيت', 'occupation' => 'خياطة', 'occupation_category' => 'other',
            'applicant_gender' => 'female', 'work_type' => 'other', 'insured' => 'no', 'relation_to_workplace' => 'independent'], 'self_employed');

        $this->assertSame('FEMALE_FREE_INCOME_NOT_ACCEPTED', $code);
    }
}
