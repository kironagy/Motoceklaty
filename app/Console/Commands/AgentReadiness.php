<?php

namespace App\Console\Commands;

use App\Models\ApplicationRequirement;
use App\Models\Branch;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use Illuminate\Console\Command;

/**
 * T19 §1: refuses cutover while any decision-gated value or required
 * business record is still missing. Every check here mirrors a "no
 * default in code" config comment from earlier tasks - this is the single
 * place that turns those into a go/no-go list.
 */
class AgentReadiness extends Command
{
    protected $signature = 'agent:readiness';

    protected $description = 'List everything still missing before the agent can go live (fails with a non-zero exit if anything is missing).';

    /** Dotted config keys that must not be null before cutover (DEC-06/13/23 etc.). */
    private const REQUIRED_CONFIG_KEYS = [
        'agent.model',
        'agent.runtime.max_model_calls',
        'agent.runtime.max_tool_calls',
        'agent.runtime.wall_clock_seconds',
        'agent.guard.number_min_value',
        'agent.fallback.message',
        'agent.handoff.max_failed_turns',
        'agent.session_gap_hours',
        'agent.recognition.match_threshold',
        'agent.recognition.similar_threshold',
    ];

    public function handle(): int
    {
        $problems = [];

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (config($key) === null) {
                $problems[] = "config('{$key}') is not set.";
            }
        }

        if (! CustomerType::where('is_active', true)->exists()) {
            $problems[] = 'No active customer type exists.';
        } else {
            foreach (CustomerType::where('is_active', true)->get() as $type) {
                if (! ApplicationRequirement::where('customer_type_id', $type->id)->exists()) {
                    $problems[] = "Customer type '{$type->key}' has no requirements configured.";
                }

                if ($type->legacy_work_status === null) {
                    $problems[] = "Customer type '{$type->key}' has no legacy_work_status set (DEC-21).";
                }
            }
        }

        if (! Branch::where('is_active', true)->exists()) {
            $problems[] = 'No active branch exists.';
        }

        if (! InstallmentPlan::where('is_active', true)->exists()) {
            $problems[] = 'No active installment plan exists.';
        }

        $approvedVersion = config('agent.instructions.approved_version');
        $liveVersion = app(\App\Domain\Settings\AgentInstructions::class)->current()['version'];

        if ($approvedVersion === null) {
            $problems[] = 'The L0 instructions have not been owner-approved (config("agent.instructions.approved_version") is not set).';
        } elseif ($approvedVersion !== $liveVersion) {
            $problems[] = "The approved L0 version ({$approvedVersion}) does not match the live instructions version ({$liveVersion}).";
        }

        if ($problems === []) {
            $this->info('Ready for cutover: all checks passed.');

            return self::SUCCESS;
        }

        $this->error('Not ready for cutover:');

        foreach ($problems as $problem) {
            $this->line("- {$problem}");
        }

        return self::FAILURE;
    }
}
