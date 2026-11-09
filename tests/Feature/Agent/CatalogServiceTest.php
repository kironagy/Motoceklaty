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

    public function test_photos_are_of_the_motorcycle_he_was_quoted(): void
    {
        // Request 4272: quoted the Figuri "VLR200", sent the Benelli "VLR 200" photos.
        $figuri = $this->machine(['name' => ' VLR200']);
        $benelli = $this->machine(['name' => 'VLR 200']);
        MotorcycleImage::create(['machine_id' => $benelli->id, 'color' => 'black', 'path' => 'b.jpg', 'is_display' => false, 'sort' => 0]);
        MotorcycleImage::create(['machine_id' => $figuri->id, 'color' => 'black', 'path' => 'f.jpg', 'is_display' => false, 'sort' => 0]);

        $staff = \App\Models\Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = \App\Models\WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = \App\Models\WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new \App\Agent\Tools\ToolContext(1, $conversation->id, null, 1, $trace->id, new \App\Agent\Runtime\TurnResultBuilder());
        \App\Domain\Conversations\QuotedMotorcycle::remember($conversation->id, $figuri->id);

        $tool = app(\App\Agent\Tools\SendMotorcycleImagesTool::class);

        $this->assertSame('MOTORCYCLE_DIFFERS_FROM_LAST_QUOTED', $tool->execute(['motorcycle_id' => $benelli->id], $ctx)->error['code']);
        $sent = $tool->execute(['motorcycle_id' => $figuri->id], $ctx);
        $this->assertTrue($sent->ok);
        $this->assertStringContainsString('VLR200', $sent->data['motorcycle']);
    }

    public function test_a_misspelled_model_comes_back_with_the_whole_catalog_never_as_not_carried(): void
    {
        // Live 2026-11-06: "TVS HXL 150" (catalog: HLX 150) was told "مش متوفرة عندنا".
        $this->machine(['name' => 'HLX 150', 'aliases' => []]);
        $this->machine(['name' => '26 Max', 'aliases' => [], 'cc' => null]);

        $staff = \App\Models\Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = \App\Models\WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = \App\Models\WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2012', 'status' => 'open']);
        $ctx = new \App\Agent\Tools\ToolContext(1, $conversation->id, null, 1, 1, new \App\Agent\Runtime\TurnResultBuilder());

        $result = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['name_query' => 'TVS HXL 150', 'kind' => 'motorcycle'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->data['items']);
        $this->assertArrayNotHasKey('not_carried', $result->data);
        $this->assertContains('HLX 150', array_column($result->data['full_catalog'], 'name'));

        // a hit still brings the catalog, so other versions he could mean are seen
        $hit = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['name_query' => 'HLX 150'], $ctx);
        $this->assertSame(['HLX 150'], array_column($hit->data['items'], 'name'));
        $this->assertCount(2, $hit->data['full_catalog']);

        // a budget with nothing inside it still names the nearest real rows
        $budget = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['max_cash_price' => 1000], $ctx);
        $this->assertTrue($budget->data['nothing_matches_these_filters']);
        $this->assertCount(2, $budget->data['full_catalog']);
    }

    public function test_a_model_we_do_not_carry_gets_the_same_size_and_so_does_a_later_what_do_you_have(): void
    {
        // Simulator 690: "srk 200" (not carried), then "ايه الموجود حاليا؟"
        // got a 150cc Dayun and 250cc Hogans.
        // as live: cc is empty, the size is in the name or the features
        $this->machine(['name' => 'دايو ٤', 'cc' => null, 'cash_price' => 39000, 'features' => [['title' => '150 cc']]]);
        $this->machine(['name' => 'L250', 'cc' => null, 'cash_price' => 70000, 'features' => [['title' => '250cc']]]);
        $this->machine(['name' => 'VLR200', 'cc' => null, 'cash_price' => 50000, 'features' => [['title' => '200cc']]]);
        $this->machine(['name' => 'وينج ٢٠٠', 'cc' => null, 'cash_price' => 39000]);

        $staff = \App\Models\Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = \App\Models\WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = \App\Models\WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new \App\Agent\Tools\ToolContext(1, $conversation->id, null, 1, $trace->id, new \App\Agent\Runtime\TurnResultBuilder());
        $tool = app(\App\Agent\Tools\SearchMotorcyclesTool::class);

        $missing = $tool->execute(['name_query' => 'srk 200'], $ctx);
        // 2026-11-09: no spelling match is not "not carried" - the whole catalog goes back and the
        // agent decides by meaning (live: "فيجوري ماكس" = 26 Max was told "مش متوفر").
        $this->assertTrue($missing->data['no_spelling_match']);
        $this->assertArrayNotHasKey('not_carried', $missing->data);
        $this->assertSame(200, $missing->data['size_in_his_words']);
        $this->assertEqualsCanonicalizing(['دايو ٤', 'L250', 'VLR200', 'وينج ٢٠٠'], array_column($missing->data['full_catalog'], 'name'));

        // the tool only reads; the runner records what the search proved
        app(\App\Agent\Runtime\ToolOutcomeRecorder::class)->record('search_motorcycles', ['name_query' => 'srk 200'], $missing->toArray(), $ctx);
        $this->assertSame(200, \App\Domain\Conversations\CustomerInterest::get($conversation->id)['cc']);

        // a photo of a 250 we do not carry moves what he is after
        \App\Domain\Conversations\CustomerInterest::remember($conversation->id, 'QJ Motor SRK 250', 250);
        $this->assertSame(['L250'], array_column($tool->execute(['available_only' => true], $ctx)->data['items'], 'name'));
        \App\Domain\Conversations\CustomerInterest::remember($conversation->id, 'srk 200', 200);

        $available = $tool->execute(['available_only' => true], $ctx);
        $this->assertEqualsCanonicalizing(['VLR200', 'وينج ٢٠٠'], array_column($available->data['items'], 'name'));
        $this->assertSame(['label' => 'srk 200', 'cc' => 200], $available->data['closest_to']);
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

    public function test_an_engine_size_he_adds_finds_the_model_of_that_size(): void
    {
        // live 2026-10-03/04: "هوجن ٤ ١٥٠ سي سي" and "هوجن 150 استيراد" were told "مش متوفر"
        $hogan = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        Machine::create(['name' => 'هوجن ٤ استيراد فرز تاني', 'brand_id' => $hogan->id, 'cash_price' => 40000, 'cc' => 150, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        Machine::create(['name' => '(cc200) هوجن ٤', 'brand_id' => $hogan->id, 'cash_price' => 45000, 'cc' => 200, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $names = fn (string $q) => array_column(app(CatalogService::class)->search(['name_query' => $q])['items'], 'name');

        $this->assertSame(['هوجن ٤ استيراد فرز تاني'], $names('هوجن ٤ ١٥٠ سي سي'));
        $this->assertSame(['هوجن ٤ استيراد فرز تاني'], $names('هوجن 150 استيراد'));
    }

    public function test_a_model_name_written_together_is_found_under_another_brand_row(): void
    {
        // live 2026-10-03 (conversations 860, 900): "keeway xroad 200" was "مش متوفر"
        Brand::create(['name' => 'كيواي', 'image' => 'k.jpg']);
        $scooters = Brand::create(['name' => 'Scooters', 'image' => 's.jpg']);
        Machine::create(['name' => 'Scooter X Road i', 'brand_id' => $scooters->id, 'cash_price' => 59000, 'cc' => 200, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $result = app(CatalogService::class)->search(['name_query' => 'keeway xroad 200']);

        $this->assertSame(['Scooter X Road i'], array_column($result['items'], 'name'));
    }
}
