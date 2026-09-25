<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetBranchInformationTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Branches\BranchService;
use App\Models\AiTrace;
use App\Models\Branch;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_governorate_and_city(): void
    {
        Branch::create(['name' => 'فرع مدينة نصر', 'governorate' => 'cairo', 'city' => 'مدينة نصر', 'address' => 'ش عباس', 'is_active' => true]);
        Branch::create(['name' => 'فرع الهرم', 'governorate' => 'giza', 'city' => 'الهرم', 'address' => 'ش الهرم', 'is_active' => true]);

        $result = app(BranchService::class)->find('cairo', null);

        $this->assertCount(1, $result['branches']);
        $this->assertSame('فرع مدينة نصر', $result['branches'][0]['name']);

        $byCity = app(BranchService::class)->find(null, 'الهرم');
        $this->assertCount(1, $byCity['branches']);
    }

    public function test_inactive_branches_excluded(): void
    {
        Branch::create(['name' => 'قديم', 'governorate' => 'cairo', 'city' => 'x', 'address' => 'x', 'is_active' => false]);

        $result = app(BranchService::class)->find('cairo', null);

        $this->assertSame([], $result['branches']);
    }

    public function test_empty_governorate_returns_list_of_governorates_with_branches(): void
    {
        Branch::create(['name' => 'فرع', 'governorate' => 'alexandria', 'city' => 'x', 'address' => 'x', 'is_active' => true]);

        $result = app(BranchService::class)->find('cairo', null);

        $this->assertSame([], $result['branches']);
        $this->assertSame(['alexandria'], $result['all_governorates_with_branches']);
    }

    public function test_tool_returns_branches(): void
    {
        Branch::create(['name' => 'فرع', 'governorate' => 'cairo', 'city' => 'x', 'address' => 'x', 'phones' => ['0100'], 'is_active' => true]);

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = app(GetBranchInformationTool::class)->execute(['governorate' => 'cairo'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(['0100'], $result->data['branches'][0]['phones']);
    }
}
