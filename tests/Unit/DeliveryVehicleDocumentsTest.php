<?php

namespace Tests\Unit;

use App\Services\ApplicationStateService;
use App\Services\Handlers\ApplicationHandler;
use App\Support\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * A courier's required documents depend on what they actually ride, not
 * only on the fact that they do delivery: a rider on a bicycle has no
 * licence to send, and asking for one stalls the application on a document
 * that does not exist. So the vehicle becomes a required question for that
 * category, and the document list branches on the answer.
 */
class DeliveryVehicleDocumentsTest extends TestCase
{
    private function handler(): ApplicationHandler
    {
        return new ApplicationHandler();
    }

    private function requiredDocuments(array $application): array
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'requiredDocuments');
        $method->setAccessible(true);

        return $method->invoke($this->handler(), $application);
    }

    public function test_bicycle_courier_is_not_asked_for_a_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'شغال دليفري',
            'work_vehicle' => 'bicycle',
        ]);

        $this->assertContains('work_app_screens', $documents);
        $this->assertNotContains('driver_license', $documents);
        // The ID card is required of everyone.
        $this->assertContains('id_card_front', $documents);
    }

    public function test_motorcycle_courier_needs_screenshot_and_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'شغال دليفري',
            'work_vehicle' => 'motorcycle',
        ]);

        $this->assertContains('work_app_screens', $documents);
        $this->assertContains('driver_license', $documents);
    }

    public function test_uber_driver_needs_id_screenshots_and_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'شغال اوبر',
            'work_vehicle' => 'car',
        ]);

        $this->assertContains('id_card_front', $documents);
        $this->assertContains('id_card_back', $documents);
        $this->assertContains('work_app_screens', $documents);
        $this->assertContains('driver_license', $documents);
    }

    public function test_vehicle_wording_is_normalized(): void
    {
        $handler = $this->handler();

        $this->assertSame('bicycle', $handler->normalizeVehicle('عجلة'));
        $this->assertSame('bicycle', $handler->normalizeVehicle('بسكلتة'));
        $this->assertSame('motorcycle', $handler->normalizeVehicle('موتوسيكل'));
        $this->assertSame('motorcycle', $handler->normalizeVehicle('موتور'));
        $this->assertSame('car', $handler->normalizeVehicle('عربية ملاكي'));
        $this->assertSame('car', $handler->normalizeVehicle('اوبر'));
        $this->assertSame('car', $handler->normalizeVehicle('car'));
    }

    /**
     * Guessing the vehicle silently changes which documents the customer
     * must produce, so an unrecognised answer stays null and gets asked
     * about rather than assumed.
     */
    public function test_unrecognised_vehicle_wording_stays_null(): void
    {
        $handler = $this->handler();

        $this->assertNull($handler->normalizeVehicle('مش فاكر'));
        $this->assertNull($handler->normalizeVehicle(''));
        $this->assertNull($handler->normalizeVehicle(null));
    }

    public function test_only_delivery_is_asked_about_the_vehicle(): void
    {
        $handler = $this->handler();

        $this->assertTrue($handler->requiresVehicleAnswer('delivery'));
        $this->assertFalse($handler->requiresVehicleAnswer('employee'));
        $this->assertFalse($handler->requiresVehicleAnswer('pension'));
        $this->assertFalse($handler->requiresVehicleAnswer('freelance'));
    }

    public function test_vehicle_is_a_missing_field_until_answered(): void
    {
        $service = new ApplicationStateService(new AddressParser());

        $application = [
            'full_name' => 'كيرلس ناجي فهيم',
            'national_id' => '29005132101234',
            'phone' => '01012345678',
            'job_type' => 'شغال دليفري',
            'income_proof' => 'لا يوجد',
            'work_address' => 'لا يوجد',
            'home_address' => 'الجيزة شارع الهرم عمارة 5 الدور 2 شقة 3 بجوار مسجد النور ملك',
            'installment_months' => 12,
        ];

        $application = $service->refreshAddressComponents($application);

        $this->assertContains(
            'work_vehicle',
            $service->missingFields($application, true, true)
        );

        $application['work_vehicle'] = 'motorcycle';

        $this->assertSame([], $service->missingFields($application, true, true));
    }

    public function test_vehicle_is_not_required_for_other_categories(): void
    {
        $service = new ApplicationStateService(new AddressParser());

        $application = [
            'full_name' => 'كيرلس ناجي فهيم',
            'national_id' => '29005132101234',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'الجيزة شارع الهرم عمارة 5 الدور 2 شقة 3 بجوار مسجد النور',
            'home_address' => 'الجيزة شارع الهرم عمارة 5 الدور 2 شقة 3 بجوار مسجد النور ملك',
            'installment_months' => 12,
        ];

        $application = $service->refreshAddressComponents($application);

        $this->assertSame([], $service->missingFields($application, false, false));
    }

    /**
     * محادثة حقيقية: الطلب اتصنّف "تاكسي" (من كلمة في رسالة قديمة) فطلب
     * رخصة قيادة، والعميل رد "انا شغال علي عجله مش معايا رخصه". العجلة
     * مالهاش رخصة - أيًا كانت الفئة - فالطلب كان بيقف على مستند مستحيل.
     */
    public function test_a_bicycle_never_needs_a_licence_whatever_the_category_says(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'سواق تاكسي',
            'work_vehicle' => 'bicycle',
        ]);

        $this->assertNotContains('driver_license', $documents);
        $this->assertNotContains('vehicle_license', $documents);
        $this->assertContains('id_card_front', $documents);
    }

    /**
     * محادثة حقيقية (رقم 01200268302): documents_required اتسجّل بـ
     * driver_license من قبل ما work_vehicle يتحدد صح، فالعميل - وهو على
     * عجلة، خلّص البطاقة وش وضهر - اتطلب منه رخصة قيادة. الحارس ده
     * بيصحّح القايمة في **أول** كل دور في مرحلة المستندات، مش بس لما
     * العميل يكتب "أنا على عجلة" صراحة في نفس الرسالة.
     */
    public function test_stale_licence_requirement_is_dropped_before_the_next_document_prompt(): void
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'enforceBicycleHasNoLicence');
        $method->setAccessible(true);

        $payload = [
            'application' => ['work_vehicle' => 'bicycle'],
            'documents_required' => ['id_card_front', 'id_card_back', 'driver_license', 'work_app_screens'],
            'documents_index' => 2,
            'documents_collected' => [
                'id_card_front' => ['path' => 'a.jpg', 'fields' => []],
                'id_card_back' => ['path' => 'b.jpg', 'fields' => []],
            ],
        ];

        $conversation = new \App\Models\WhatsappConversation();

        $changed = $method->invokeArgs($this->handler(), [$conversation, &$payload]);

        $this->assertTrue($changed);
        $this->assertSame(['id_card_front', 'id_card_back', 'work_app_screens'], $payload['documents_required']);
        $this->assertSame(2, $payload['documents_index']);
    }

    public function test_the_guard_does_nothing_when_the_list_is_already_correct(): void
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'enforceBicycleHasNoLicence');
        $method->setAccessible(true);

        $payload = [
            'application' => ['work_vehicle' => 'motorcycle'],
            'documents_required' => ['id_card_front', 'id_card_back', 'work_app_screens', 'driver_license'],
            'documents_index' => 2,
            'documents_collected' => [],
        ];

        $conversation = new \App\Models\WhatsappConversation();

        $changed = $method->invokeArgs($this->handler(), [$conversation, &$payload]);

        $this->assertFalse($changed);
        $this->assertSame(['id_card_front', 'id_card_back', 'work_app_screens', 'driver_license'], $payload['documents_required']);
    }

    private function guard(array $application, array $extracted, string $message): array
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'guardStoredWorkVehicle');
        $method->setAccessible(true);

        return $method->invoke($this->handler(), $application, $extracted, $message);
    }

    /**
     * محادثة العجلة: العميل قال "شغال طلبات على عجلة" فاتسجلت bicycle،
     * وبعدين بعت اسمه وعنوانه - والاستخراج رجّع motorcycle من سياق
     * المحادثة (اسم المكنة اللي بيشتريها). لو الدمج قبلها، قايمة
     * المستندات كانت بتتبني وفيها رخصة قيادة مستحيل توصل.
     */
    public function test_extraction_cannot_overwrite_a_stored_vehicle_the_customer_did_not_restate(): void
    {
        $extracted = $this->guard(
            ['work_vehicle' => 'bicycle'],
            ['work_vehicle' => 'motorcycle', 'full_name' => 'احمد سيد حسين علي'],
            'احمد سيد حسين علي 29011260101839 ٦ اكتوبر شارع محمد عماره ١٢'
        );

        $this->assertArrayNotHasKey('work_vehicle', $extracted);
        $this->assertSame('احمد سيد حسين علي', $extracted['full_name']);
    }

    public function test_customer_can_still_correct_their_vehicle_explicitly(): void
    {
        $extracted = $this->guard(
            ['work_vehicle' => 'bicycle'],
            ['work_vehicle' => 'motorcycle'],
            'انا بقيت شغال على موتوسيكل دلوقتي'
        );

        $this->assertSame('motorcycle', $extracted['work_vehicle']);
    }

    public function test_first_vehicle_answer_is_still_taken_from_extraction(): void
    {
        $extracted = $this->guard(
            [],
            ['work_vehicle' => 'motorcycle'],
            'شغال دليفري'
        );

        $this->assertSame('motorcycle', $extracted['work_vehicle']);
    }

    public function test_a_repeat_of_the_same_vehicle_passes_through(): void
    {
        $extracted = $this->guard(
            ['work_vehicle' => 'bicycle'],
            ['work_vehicle' => 'عجلة'],
            'اه على عجله'
        );

        $this->assertSame('عجلة', $extracted['work_vehicle']);
    }

    private function contradicts(array $payload, string $reply): bool
    {
        $method = new \ReflectionMethod(ApplicationHandler::class, 'replyContradictsPendingDocument');
        $method->setAccessible(true);

        return $method->invoke($this->handler(), $payload, $reply);
    }

    /**
     * الرسالة اللي بتناقض نفسها: الرد الحر بيقول "مش محتاجين رخصة قيادة
     * خالص" والـ prompt الثابت متلزق تحته على طول "ابعتلي رخصة القيادة".
     */
    public function test_a_reply_that_denies_the_licence_contradicts_a_pending_licence(): void
    {
        $payload = [
            'documents_required' => ['id_card_front', 'id_card_back', 'driver_license'],
            'documents_index' => 2,
        ];

        $this->assertTrue($this->contradicts(
            $payload,
            'بما إن حضرتك شغال دليفري بعجلة، محتاجين البطاقة وسكرين من التطبيق. ومش محتاجين رخصة قيادة خالص.'
        ));
    }

    public function test_an_ordinary_answer_does_not_count_as_a_contradiction(): void
    {
        $payload = [
            'documents_required' => ['id_card_front', 'driver_license'],
            'documents_index' => 1,
        ];

        $this->assertFalse($this->contradicts($payload, 'أيوه ينفع تصورها بالموبايل عادي، المهم تكون واضحة.'));
        $this->assertFalse($this->contradicts($payload, 'الرخصة لازم تكون سارية وباسم حضرتك.'));
    }

    public function test_no_contradiction_when_the_pending_document_is_not_a_licence(): void
    {
        $payload = [
            'documents_required' => ['id_card_front', 'work_app_screens'],
            'documents_index' => 1,
        ];

        $this->assertFalse($this->contradicts($payload, 'ومش محتاجين رخصة قيادة خالص، ابعتلي السكرين بس.'));
    }
}
