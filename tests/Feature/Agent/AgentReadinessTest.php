<?php

namespace Tests\Feature\Agent;

use App\Models\ApplicationRequirement;
use App\Models\Branch;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\RequirementField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_nothing_is_configured(): void
    {
        config([
            'agent.model' => null,
            'agent.runtime.max_model_calls' => null,
            'agent.runtime.max_tool_calls' => null,
            'agent.runtime.wall_clock_seconds' => null,
            'agent.guard.number_min_value' => null,
            'agent.fallback.message' => null,
            'agent.handoff.max_failed_turns' => null,
            'agent.session_gap_hours' => null,
            'agent.recognition.match_threshold' => null,
            'agent.recognition.similar_threshold' => null,
            'agent.instructions.approved_version' => null,
        ]);

        $this->artisan('agent:readiness')
            ->assertExitCode(1)
            ->expectsOutputToContain("config('agent.model') is not set.")
            ->expectsOutputToContain('No active customer type exists.')
            ->expectsOutputToContain('No active branch exists.')
            ->expectsOutputToContain('No active installment plan exists.')
            ->expectsOutputToContain('L0 instructions have not been owner-approved');
    }

    public function test_passes_once_every_prerequisite_is_met(): void
    {
        config([
            'agent.model' => 'gemini-3.1-flash-lite',
            'agent.runtime.max_model_calls' => 6,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
            'agent.guard.number_min_value' => 100,
            'agent.fallback.message' => 'حصل عطل بسيط.',
            'agent.handoff.max_failed_turns' => 2,
            'agent.session_gap_hours' => 6,
            'agent.recognition.match_threshold' => 0.8,
            'agent.recognition.similar_threshold' => 0.5,
            'agent.instructions.approved_version' => 'v2.1.0',
        ]);

        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee', 'legacy_work_status' => 'employee', 'is_active' => true]);
        $field = RequirementField::create(['key' => 'full_name', 'label' => 'Full name', 'data_type' => 'person_name', 'scope' => 'application', 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        Branch::create(['name' => 'الفرع الرئيسي', 'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'address' => 'شارع 1', 'is_active' => true]);
        $system = InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        InstallmentPlan::where('installment_system_id', $system->id)->update(['is_active' => true]);

        $this->artisan('agent:readiness')->assertExitCode(0);
    }
}
