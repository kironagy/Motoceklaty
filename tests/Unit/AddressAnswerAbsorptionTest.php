<?php

namespace Tests\Unit;

use App\Services\ApplicationStateService;
use App\Services\Handlers\ApplicationHandler;
use App\Support\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * محادثة حقيقية: البوت سأل "لسه محتاج رقم العمارة والدور ورقم الشقة
 * وعلامة مميزة"، العميل رد "فيلا ١١٥"، والبوت أعاد نفس الجملة حرفيًا.
 * السؤال ده بيطلب أكتر من مكوّن، فمكانش بيتسجّل عنه أي "سؤال مفتوح"،
 * والرد كان معتمد بالكامل على استخراج الـ LLM - ولما رجع فاضي، ضاع.
 */
class AddressAnswerAbsorptionTest extends TestCase
{
    private function service(): ApplicationStateService
    {
        return new ApplicationStateService(new AddressParser());
    }

    public function test_a_short_answer_fills_the_components_it_names(): void
    {
        $application = [
            'home_address' => 'اكتوبر ١٥ أ - مربع ٣',
            'home_address_components' => ['city' => 'اكتوبر', 'area' => 'مربع ٣'],
        ];

        $application = $this->service()->absorbAddressAnswer($application, 'home_address', 'فيلا ١١٥');

        $this->assertSame('115', $application['home_address_components']['building']);
        $this->assertSame('villa', $application['home_address_components']['residence_type']);
        $this->assertContains('building', $application['home_address_newly_received_components']);
        $this->assertStringContainsString('فيلا ١١٥', $application['home_address']);
        $this->assertNotContains('floor', $application['home_address_missing_components']);
    }

    public function test_an_answer_never_overwrites_a_component_already_known(): void
    {
        $application = [
            'home_address' => 'المهندسين شارع جامعة الدول',
            'home_address_components' => ['area' => 'المهندسين', 'street' => 'جامعه الدول'],
        ];

        $application = $this->service()->absorbAddressAnswer($application, 'home_address', 'عماره ١٢ إيجار');

        $this->assertSame('جامعه الدول', $application['home_address_components']['street']);
        $this->assertSame('12', $application['home_address_components']['building']);
        $this->assertSame('إيجار', $application['home_address_components']['ownership']);
    }

    public function test_a_message_that_is_not_address_data_changes_nothing(): void
    {
        $application = [
            'home_address' => 'المهندسين شارع جامعة الدول',
            'home_address_components' => ['area' => 'المهندسين', 'street' => 'جامعه الدول'],
        ];

        $this->assertSame(
            $application,
            $this->service()->absorbAddressAnswer($application, 'home_address', 'ليه محتاج كل ده؟')
        );
    }

    /**
     * السؤال اللي بيطلب أكتر من مكوّن لازم يسجّل الحقل بتاعه، عشان الرد
     * اللي بعده يتقرا في نفس الحقل بدل ما يضيع.
     */
    public function test_a_multi_component_question_remembers_which_address_it_asked_about(): void
    {
        $application = [
            'home_address' => 'اكتوبر ١٥ أ - مربع ٣',
            'home_address_missing_components' => ['building', 'floor', 'apartment', 'landmark'],
        ];

        $askedComponent = null;
        $askedField = null;

        $this->service()->questionForMissing(
            ['home_address'],
            $application,
            [],
            0,
            [],
            true,
            $askedComponent,
            $askedField
        );

        $this->assertSame('home_address', $askedField);
        $this->assertNull($askedComponent);
    }

    /**
     * "انا شغال علي عجله مش معايا رخصه" وسط مرحلة المستندات: العجلة
     * مالهاش رخصة، والطلب كان بيقف على مستند مستحيل يوصل.
     */
    public function test_a_licence_denial_is_recognised(): void
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'messageDeniesLicense');
        $method->setAccessible(true);

        $handler = new ApplicationHandler();

        $this->assertTrue($method->invoke($handler, 'انا شغال علي عجله مش معايا رخصه'));
        $this->assertTrue($method->invoke($handler, 'مليش رخصة أصلاً'));
        $this->assertTrue($method->invoke($handler, 'معنديش رخصة'));
        $this->assertFalse($method->invoke($handler, 'الرخصة سارية وهبعتها دلوقتي'));
    }
}
