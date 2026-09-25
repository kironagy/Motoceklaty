<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\LookupMotorcycleSpecsOnlineTool;
use App\Agent\Tools\ToolContext;
use App\Models\Brand;
use App\Models\Machine;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LookupMotorcycleSpecsOnlineToolTest extends TestCase
{
    use RefreshDatabase;

    private function machine(array $attributes = []): Machine
    {
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);

        return Machine::create($attributes + [
            'name' => 'F250', 'brand_id' => $brand->id, 'cash_price' => 69000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal',
            'cc' => 250, 'model_year' => 2025,
        ]);
    }

    private function lookup(Machine $machine, array $specs = ['مقاس الكاوتش']): array
    {
        $ctx = new ToolContext(1, 1, null, 1, 1, new TurnResultBuilder());

        return app(LookupMotorcycleSpecsOnlineTool::class)
            ->execute(['motorcycle_id' => $machine->id, 'specs' => $specs], $ctx)
            ->toArray();
    }

    private function geminiReturns(array $json, array $sources = ['https://example.com/f250']): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('generateText')->andReturn([
            'ok' => true, 'text' => '```json'.json_encode($json, JSON_UNESCAPED_UNICODE).'```', 'sources' => $sources,
        ]);
        $this->app->instance(GeminiClient::class, $gemini);
    }

    public function test_returns_specs_for_the_exact_version(): void
    {
        $this->geminiReturns(['found' => true, 'matched_model' => 'F250', 'matched_cc' => 250, 'matched_year' => 2025,
            'specs' => [['name' => 'مقاس الكاوتش', 'value' => '90/90-18']]]);

        $result = $this->lookup($this->machine());

        $this->assertTrue($result['data']['found']);
        $this->assertSame('90/90-18', $result['data']['specs'][0]['value']);
        $this->assertSame(2025, $result['data']['version']['model_year']);
    }

    public function test_rejects_a_different_year_or_engine(): void
    {
        $this->geminiReturns(['found' => true, 'matched_model' => 'F250', 'matched_cc' => 200, 'matched_year' => 2025,
            'specs' => [['name' => 'مقاس الكاوتش', 'value' => '90/90-18']]]);

        $result = $this->lookup($this->machine());

        $this->assertFalse($result['data']['found']);
        $this->assertArrayNotHasKey('specs', $result['data']);
    }

    public function test_rejects_an_answer_with_no_web_source(): void
    {
        $this->geminiReturns(['found' => true, 'matched_cc' => 250, 'matched_year' => 2025,
            'specs' => [['name' => 'مقاس الكاوتش', 'value' => '90/90-18']]], sources: []);

        $this->assertFalse($this->lookup($this->machine())['data']['found']);
    }

    public function test_does_not_search_when_the_version_is_not_defined(): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldNotReceive('generateText');
        $this->app->instance(GeminiClient::class, $gemini);

        $result = $this->lookup($this->machine(['model_year' => null]));

        $this->assertFalse($result['data']['found']);
        $this->assertSame(['model_year'], $result['data']['missing_in_dashboard']);
    }

    public function test_a_found_result_is_cached(): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('generateText')->once()->andReturn(['ok' => true, 'sources' => ['https://example.com'],
            'text' => json_encode(['found' => true, 'matched_cc' => 250, 'matched_year' => 2025,
                'specs' => [['name' => 'سعة التنك', 'value' => '11.5 لتر']]], JSON_UNESCAPED_UNICODE)]);
        $this->app->instance(GeminiClient::class, $gemini);
        $machine = $this->machine();

        $this->lookup($machine, ['سعة التنك']);
        $result = $this->lookup($machine, ['سعة التنك']);

        $this->assertSame('11.5 لتر', $result['data']['specs'][0]['value']);
    }

    public function test_the_details_tool_exposes_the_model_year(): void
    {
        $machine = $this->machine(['specifications' => ['مقاس الكاوتش' => '90/90-18']]);

        $item = app(\App\Domain\Catalog\CatalogService::class)->details([$machine->id])['items'][0];

        $this->assertSame(2025, $item['model_year']);
        $this->assertSame('90/90-18', $item['specifications']['مقاس الكاوتش']);
    }

    public function test_a_failed_search_is_not_retried_right_away(): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('generateText')->once()->andReturn(['ok' => false, 'error' => 'quota']);
        $this->app->instance(GeminiClient::class, $gemini);
        $machine = $this->machine();

        $this->assertSame('LOOKUP_UNAVAILABLE', $this->lookup($machine)['error']['code']);
        $this->assertSame('LOOKUP_UNAVAILABLE', $this->lookup($machine, ['الوزن'])['error']['code']);
    }
}
