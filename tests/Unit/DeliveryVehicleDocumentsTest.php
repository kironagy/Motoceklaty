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

        $this->assertContains('trips_screenshot', $documents);
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

        $this->assertContains('trips_screenshot', $documents);
        $this->assertContains('driver_license', $documents);
    }

    public function test_uber_driver_needs_id_screenshot_and_licence(): void
    {
        $documents = $this->requiredDocuments([
            'job_type' => 'شغال اوبر',
            'work_vehicle' => 'car',
        ]);

        $this->assertContains('id_card_front', $documents);
        $this->assertContains('id_card_back', $documents);
        $this->assertContains('trips_screenshot', $documents);
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
}
