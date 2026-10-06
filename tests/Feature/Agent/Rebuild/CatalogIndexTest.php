<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Domain\Catalog\CatalogService;
use App\Domain\Conversations\QuotedOffer;
use App\Models\Application;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild CTX-004 (catalog index only while motorcycles are the talk) and CAT-002 (showroom nicknames as aliases). */
class CatalogIndexTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogService::invalidateIndexCache();
        $brand = Brand::create(['name' => 'دايو', 'image' => 'b.jpg']);
        $this->machine = Machine::create(['name' => 'دايو 2', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        Machine::create(['name' => 'دايو 2 استيراد', 'brand_id' => $brand->id, 'cash_price' => 45000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 90000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
    }

    private function system($conversation): string
    {
        return app(ContextBuilder::class)->build($this->turnFor($conversation, 'تمام'))->system;
    }

    private function application($conversation, ?int $machineId): Application
    {
        $type = CustomerType::firstOrCreate(['key' => 'employee'], ['label' => 'موظف']);

        return Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting', 'machine_id' => $machineId]);
    }

    public function test_the_index_is_sent_while_he_is_choosing(): void
    {
        $conversation = $this->conversation();
        $this->assertStringContainsString('## فهرس الكتالوج', $this->system($conversation));

        $this->application($conversation, null);
        $this->assertStringContainsString('## فهرس الكتالوج', $this->system($conversation));
    }

    public function test_the_index_is_left_out_while_he_sends_papers_for_a_chosen_motorcycle(): void
    {
        $conversation = $this->conversation();
        $this->application($conversation, $this->machine->id);

        $this->assertStringNotContainsString('## فهرس الكتالوج', $this->system($conversation));
    }

    public function test_a_fresh_offer_brings_the_index_back(): void
    {
        $conversation = $this->conversation();
        $this->application($conversation, $this->machine->id);
        QuotedOffer::addToLedger($conversation->id, $this->machine->id, ['months' => 12, 'monthly_payment' => 5000]);

        $this->assertStringContainsString('## فهرس الكتالوج', $this->system($conversation->refresh()));
    }

    public function test_showroom_nicknames_are_found_by_search(): void
    {
        (require database_path('migrations/2026_10_05_200000_add_nickname_aliases_to_machines.php'))->up();

        $search = fn (string $q) => collect(app(CatalogService::class)->search(['name_query' => $q])['items'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['دايو 2', 'دايو 2 استيراد'], $search('النحلة'));
        $this->assertSame(['Z250'], $search('زد'));
        $this->assertContains('زد', Machine::where('name', 'Z250')->value('aliases'));
    }
}
