<?php

namespace Tests\Feature;

use App\Domain\Branches\BranchService;
use App\Domain\Settings\AgentInstructions;
use App\Models\Branch;
use App\Models\BusinessMemory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredGuideAndBranchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    public function test_guide_table_is_removed_and_model_writes_still_work(): void
    {
        (require database_path('migrations/2026_10_25_120000_create_guide_events_table.php'))->up();
        DB::table('guide_events')->insert(['event' => 'created', 'actor_name' => 'test', 'subject_label' => 'test', 'description' => 'test', 'created_at' => now()]);
        (require database_path('migrations/2026_11_10_010000_remove_guide_events.php'))->up();
        (require database_path('migrations/2026_09_23_110000_create_branches_table.php'))->up();
        $branch = Branch::create(['name' => 'فرع عين شمس', 'is_active' => true, 'governorate' => 'cairo', 'city' => 'test', 'address' => 'test']);
        $branch->update(['city' => 'عين شمس']);
        $this->assertSame('عين شمس', $branch->fresh()->city);
        $this->assertFalse(Schema::hasTable('guide_events'));
        $this->assertNotContains('admin/guide', array_map(fn ($route) => $route->uri(), app('router')->getRoutes()->getRoutes()));
    }

    public function test_closed_branch_is_excluded_and_memory_and_instructions_are_updated_idempotently(): void
    {
        Schema::create('staff', fn (Blueprint $table) => $table->id());
        foreach (['2026_09_23_110000_create_branches_table.php', '2026_09_22_140000_create_business_memories_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        // Isolate instruction publication from the rest of the application schema.
        $this->mock(\App\Domain\Teaching\LessonSync::class)->shouldReceive('withLessons')->twice()->andReturnUsing(fn ($text) => $text);
        $instructions = $this->mock(AgentInstructions::class);
        $policy = trim(file_get_contents(resource_path('agent/policies/current-branches.md')));
        $instructions->shouldReceive('current')->twice()->andReturn(['text' => 'existing'], ['text' => 'existing'."\n\n".$policy]);
        $instructions->shouldReceive('publish')->once()->with('existing'."\n\n".$policy, \Mockery::any());
        foreach (['العمرانية', 'جسر السويس', 'عين شمس', 'الخصوص'] as $name) {
            Branch::create(['name' => 'فرع '.$name, 'is_active' => true, 'governorate' => 'cairo', 'city' => 'test', 'address' => 'test']);
        }
        $migration = require database_path('migrations/2026_11_10_010100_update_current_branches.php');
        $migration->up();
        $migration->up();
        $result = app(BranchService::class)->find(null, null);
        $this->assertCount(3, $result['branches']);
        $this->assertNotContains('فرع الخصوص', array_column($result['branches'], 'name'));
        $this->assertSame(1, BusinessMemory::where('key', 'current_branches')->count());
        $this->assertSame($policy, BusinessMemory::where('key', 'current_branches')->first()->content);
        $this->assertFalse(Branch::where('name', 'فرع الخصوص')->first()->is_active);
    }
}
