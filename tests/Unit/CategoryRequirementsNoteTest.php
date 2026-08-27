<?php

namespace Tests\Unit;

use App\Services\ApplicationStateService;
use App\Support\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * Conversation 254: the moment the customer said he works delivery, the
 * bot answered "لو دليفري محتاجين رخصة سارية وسكرين رحلات" - and only
 * afterwards asked what he actually rides. He rides a bicycle. A
 * bicycle courier is never asked for a driving licence (see
 * requiredDocuments()), so the first requirement he was ever told was
 * one that does not apply to him, and his reply - "مش معايا رخصه" -
 * read as a blocker he had raised against himself.
 *
 * The vehicle is already a required field for exactly this category, for
 * exactly this reason. The requirements note has to wait for it too.
 */
class CategoryRequirementsNoteTest extends TestCase
{
    private function service(): ApplicationStateService
    {
        return new ApplicationStateService(new AddressParser());
    }

    public function test_delivery_requirements_wait_for_the_vehicle(): void
    {
        $this->assertFalse(
            $this->service()->shouldSendCategoryNote('delivery', ['work_vehicle' => null])
        );
    }

    public function test_delivery_requirements_are_sent_once_the_vehicle_is_known(): void
    {
        $this->assertTrue(
            $this->service()->shouldSendCategoryNote('delivery', ['work_vehicle' => 'bicycle'])
        );
    }

    public function test_other_categories_are_not_held_back(): void
    {
        $this->assertTrue(
            $this->service()->shouldSendCategoryNote('employee', ['work_vehicle' => null])
        );
        $this->assertTrue(
            $this->service()->shouldSendCategoryNote('pension', [])
        );
    }
}
