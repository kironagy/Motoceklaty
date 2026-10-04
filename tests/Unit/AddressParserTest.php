<?php

namespace Tests\Unit;

use App\Domain\Applications\AddressParser;
use PHPUnit\Framework\TestCase;

/** Addresses from the server's bot requests that reached the dashboard unsplit. */
class AddressParserTest extends TestCase
{
    private function parse(string $line): array
    {
        return (new AddressParser())->parse($line);
    }

    public function test_a_district_gives_the_governorate(): void
    {
        $this->assertSame(['building_number' => '34', 'street' => 'الشرفاء العشرين', 'governorate' => 'الجيزة', 'area' => 'فيصل'], $this->parse('34 شارع الشرفاء العشرين فيصل'));
    }

    public function test_street_branch_and_the_words_no_marker_took(): void
    {
        $this->assertSame(['branch_street' => 'زغلول', 'street' => 'الامير', 'governorate' => 'الجيزة', 'area' => 'الهرم محطه مشعل'], $this->parse('الهرم محطه مشعل شارع الامير متفرع من شارع زغلول'));
    }

    public function test_floor_apartment_building_and_landmark(): void
    {
        $parts = $this->parse('القاهره، زهراء المعادي، ميدان بيتشو، شارع السعاده عماره 22 بجوار سوبر ماركت بيم');

        $this->assertSame('22', $parts['building_number']);
        $this->assertSame('السعاده', $parts['street']);
        $this->assertSame('سوبر ماركت بيم', $parts['landmark']);
        $this->assertSame('القاهرة', $parts['governorate']);
        $this->assertStringContainsString('زهراء المعادي', $parts['area']);

        $this->assertSame(['floor' => 'التاني', 'apartment' => '5', 'building_number' => '25', 'street' => 'مجله روماني'], $this->parse('25شارع مجله روماني الدور التاني شقه 5'));
    }

    public function test_the_area_and_governorate_leave_the_end_of_a_street(): void
    {
        $this->assertSame(['branch_street' => 'جمال عبد الناصر', 'building_number' => '48', 'street' => 'الحرية', 'governorate' => 'الجيزة', 'area' => 'المنيب'],
            $this->parse('48ش الحرية متفرع من جمال عبد الناصر المنيب الجيزه'));
    }

    public function test_a_street_named_after_a_district_stays_a_street(): void
    {
        $this->assertSame(['street' => 'الهرم', 'governorate' => 'الجيزة'], $this->parse('شارع الهرم'));
    }

    public function test_a_governorate_outside_cairo(): void
    {
        $this->assertSame('المنوفية', $this->parse('بركة السبع شرق المنوفيه')['governorate']);
    }

    public function test_an_unknown_place_reports_nothing_for_the_place(): void
    {
        $this->assertSame([null, null], (new AddressParser())->placeIn('شارع النور عماره 5'));
    }
}
