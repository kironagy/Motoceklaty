<?php

namespace Tests\Feature\Agent;

use App\Domain\Catalog\CatalogService;
use App\Models\Brand;
use App\Models\Machine;
use App\Models\MotorcycleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function machine(array $overrides = []): Machine
    {
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'brands/bajaj.jpg']);

        return Machine::create(array_merge([
            'name' => 'بوكسر 150',
            'aliases' => ['Boxer 150', 'بوكسر'],
            'brand_id' => $brand->id,
            'cash_price' => 50000,
            'installment_price' => 55000,
            'cc' => 150,
            'is_active' => true,
            'availability' => 'in_stock',
            'type' => 'normal',
        ], $overrides));
    }

    public function test_search_by_alias_with_arabic_variants_and_indic_digits(): void
    {
        $this->machine();

        $result = app(CatalogService::class)->search(['name_query' => 'بوكسر ١٥٠']);

        $this->assertCount(1, $result['items']);
        $this->assertSame('بوكسر 150', $result['items'][0]['name']);
    }

    public function test_search_matches_english_alias(): void
    {
        $this->machine();

        $result = app(CatalogService::class)->search(['name_query' => 'Boxer']);

        $this->assertCount(1, $result['items']);
    }

    public function test_filters_and_limit_cap_and_inactive_excluded(): void
    {
        $this->machine(['name' => 'A', 'cc' => 100]);
        $this->machine(['name' => 'B', 'cc' => 200]);
        $this->machine(['name' => 'C', 'cc' => 300, 'is_active' => false]);

        $result = app(CatalogService::class)->search(['cc_min' => 150, 'limit' => 20]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('B', $result['items'][0]['name']);

        $capped = app(CatalogService::class)->search(['limit' => 50]);
        $this->assertLessThanOrEqual(8, count($capped['items']));
    }

    public function test_details_for_three_ids_and_unknown_id_error(): void
    {
        $a = $this->machine(['name' => 'A']);
        $b = $this->machine(['name' => 'B']);
        $c = $this->machine(['name' => 'C']);

        $result = app(CatalogService::class)->details([$a->id, $b->id, $c->id]);
        $this->assertCount(3, $result['items']);
        $this->assertSame([], $result['unknown_ids']);

        $withUnknown = app(CatalogService::class)->details([$a->id, 999999]);
        $this->assertSame([999999], $withUnknown['unknown_ids']);
    }

    public function test_images_queued_with_recent_duplicate_skip(): void
    {
        config(['agent.images.resend_window_minutes' => 60]);

        $machine = $this->machine();
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => 'black', 'path' => 'a.jpg', 'is_display' => false, 'sort' => 0]);

        $result = app(CatalogService::class)->images($machine->id, 'black', 4);

        $this->assertSame(['a.jpg'], $result['paths']);
        $this->assertSame(['black'], $result['colors_available']);
    }

    public function test_images_fall_back_to_display_image_and_match_hex_by_arabic_name(): void
    {
        $machine = $this->machine();
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => '#f50606', 'path' => 'red.jpg', 'is_display' => true, 'sort' => 0]);
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => '#0d0d0d', 'path' => 'black.jpg', 'is_display' => true, 'sort' => 1]);

        $service = app(CatalogService::class);

        $this->assertSame(['red.jpg', 'black.jpg'], $service->images($machine->id, null, 4)['paths']);
        $this->assertSame(['أحمر', 'أسود'], $service->images($machine->id, null, 4)['colors_available']);
        $this->assertSame(['red.jpg'], $service->images($machine->id, 'الحمرا', 4)['paths']);
        $this->assertSame(['black.jpg'], $service->images($machine->id, 'black', 4)['paths']);
        $this->assertSame([], $service->images($machine->id, 'لبني', 4)['paths']);
    }

    public function test_index_lines_cached_and_invalidated_on_save(): void
    {
        $machine = $this->machine();

        $lines = app(CatalogService::class)->indexLines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('بوكسر 150', $lines[0]);

        $machine->update(['name' => 'اسم جديد']);

        $linesAfter = app(CatalogService::class)->indexLines();
        $this->assertStringContainsString('اسم جديد', $linesAfter[0]);
    }
}
