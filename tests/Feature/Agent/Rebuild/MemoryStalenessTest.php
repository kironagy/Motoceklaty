<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Domain\Memory\CustomerMemory;
use App\Models\Application;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild MEM-003: what was true yesterday is not shown as now. */
class MemoryStalenessTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private CustomerMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memory = app(CustomerMemory::class);
    }

    private function prompt($conversation): string
    {
        return (string) $this->memory->forPrompt($conversation->customer_id);
    }

    public function test_topic_and_open_question_expire_after_a_day(): void
    {
        $conversation = $this->conversation();
        $this->memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['topic' => 'أسعار الهوجن', 'open_question' => 'مستني صورة البطاقة']);

        $this->travel(23)->hours();
        $this->assertStringContainsString('مستني صورة البطاقة', $this->prompt($conversation));

        $this->travel(2)->hours();
        $this->assertStringNotContainsString('مستني صورة البطاقة', $this->prompt($conversation));
        $this->assertStringNotContainsString('أسعار الهوجن', $this->prompt($conversation));
    }

    public function test_a_stage_change_ends_the_topic_at_once(): void
    {
        $conversation = $this->conversation();
        $this->memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['open_question' => 'هيبعت شغله']);
        $this->assertStringContainsString('هيبعت شغله', $this->prompt($conversation));

        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);

        $this->assertStringNotContainsString('هيبعت شغله', $this->prompt($conversation));
    }

    public function test_objections_last_a_week(): void
    {
        $conversation = $this->conversation();
        $this->memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['objections' => ['المقدم كبير']]);

        $this->travel(6)->days();
        $this->assertStringContainsString('المقدم كبير', $this->prompt($conversation));

        $this->travel(2)->days();
        $this->assertStringNotContainsString('المقدم كبير', $this->prompt($conversation));
    }

    public function test_interest_in_a_motorcycle_decays_after_a_month_unless_he_applied(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        $asked = Machine::create(['name' => 'Z250', 'brand_id' => $brand->id, 'cash_price' => 90000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $applied = Machine::create(['name' => 'H250', 'brand_id' => $brand->id, 'cash_price' => 80000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id,
            'status' => 'submitted', 'machine_id' => $applied->id, 'submitted_at' => now()]);

        $this->memory->recordToolEvent($conversation->customer_id, 'get_installment_offer', ['motorcycle_id' => $asked->id], ['ok' => true, 'data' => []]);
        $this->memory->recordToolEvent($conversation->customer_id, 'submit_application', [], ['ok' => true, 'data' => ['submitted' => true]]);

        $this->travel(29)->days();
        $this->assertStringContainsString('Z250', $this->prompt($conversation));

        $this->travel(2)->days();
        $prompt = $this->prompt($conversation);
        $this->assertStringNotContainsString('Z250', $prompt);
        $this->assertStringContainsString('H250', $prompt);

        // the next write drops it from the stored memory too
        $this->memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['topic' => 'متابعة الطلب']);
        $stored = $this->memory->get($conversation->customer_id)['motorcycles'];
        $this->assertSame(['H250'], array_values(array_column($stored, 'name')));
    }

    public function test_old_items_without_their_own_time_use_the_block_time(): void
    {
        $conversation = $this->conversation();
        Customer::whereKey($conversation->customer_id)->update(['memory' => json_encode([
            'conversation' => ['topic' => 'قديم', 'updated_at' => now()->subDays(2)->toIso8601String()],
        ])]);

        $this->assertStringNotContainsString('قديم', $this->prompt($conversation));
    }
}
