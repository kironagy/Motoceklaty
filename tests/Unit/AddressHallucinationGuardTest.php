<?php

namespace Tests\Unit;

use App\Services\ApplicationStateService;
use App\Support\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * محادثة حقيقية: العميل بعت "٦ اكتوبر ١٥ أ مربع ٣ فيلا ١١٥" (عنوان ناقص
 * حقيقي)، والاستخراج بالـ LLM رجّع home_address ومعاه تفاصيل مختلقة
 * تمامًا - "رقم العماره ٢ والدور التاني شقه ١٥ امام سوبر مركت بيم،
 * إيجار" - العميل ماكتبهاش خالص، ومعاها status = "complete" كذب.
 * groundAddressInRawMessage() هي الحارس اللي بيمنع الاختراع ده يتخزّن.
 */
class AddressHallucinationGuardTest extends TestCase
{
    private function service(): ApplicationStateService
    {
        return new ApplicationStateService(new AddressParser());
    }

    public function test_fabricated_components_never_get_stored(): void
    {
        $application = [
            'home_address' => '٦ اكتوبر ١٥ أ مربع ٣ فيلا ١١٥، رقم العماره ٢ والدور التاني شقه ١٥ امام سوبر مركت بيم، إيجار',
        ];

        $application = $this->service()->groundAddressInRawMessage(
            $application,
            'home_address',
            '٦ اكتوبر ١٥ أ مربع ٣ فيلا ١١٥'
        );

        $this->assertSame('٦ اكتوبر ١٥ أ مربع ٣ فيلا ١١٥', $application['home_address']);
        $this->assertArrayNotHasKey('floor', array_filter($application['home_address_components']));
        $this->assertArrayNotHasKey('apartment', array_filter($application['home_address_components']));
        $this->assertArrayNotHasKey('landmark', array_filter($application['home_address_components']));
        $this->assertArrayNotHasKey('ownership', array_filter($application['home_address_components']));
        $this->assertSame('villa', $application['home_address_components']['residence_type']);
    }

    public function test_a_message_with_no_address_content_leaves_the_field_untouched(): void
    {
        // The model echoed a value back (or invented one) but the
        // customer's own message this turn has no address in it at all -
        // e.g. they were answering a different question. Trust silence.
        $application = ['home_address' => 'شارع وهمي مختلق من الموديل'];

        $result = $this->service()->groundAddressInRawMessage($application, 'home_address', 'تمام يا فندم');

        $this->assertSame($application, $result);
    }

    public function test_raw_text_accumulates_across_turns_without_duplicating(): void
    {
        $application = ['home_address' => 'المهندسين شارع جامعة الدول'];
        $application = $this->service()->groundAddressInRawMessage($application, 'home_address', 'المهندسين شارع جامعة الدول');

        $application = $this->service()->groundAddressInRawMessage($application, 'home_address', 'عماره ١٢ إيجار');

        $this->assertSame('المهندسين شارع جامعة الدول - عماره ١٢ إيجار', $application['home_address']);
        $this->assertSame('12', $application['home_address_components']['building']);
    }
}
