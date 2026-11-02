<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('installment_systems')->whereIn('name', ['امان', 'امان - القاهرة', 'امان - الجيزة', 'امان بدون مصاريف'])->get() as $system) {
                $annual = $system->name === 'امان بدون مصاريف' ? 31 : 21;
                $plans = json_decode($system->plans, true) ?? [];
                foreach ($plans as &$plan) {
                    $plan['interest'] = (string) ($annual * (int) $plan['months'] / 12);
                }
                unset($plan);
                DB::table('installment_systems')->where('id', $system->id)->update(['plans'=>json_encode($plans, JSON_UNESCAPED_UNICODE)]);
                foreach (DB::table('installment_plans')->where('installment_system_id', $system->id)->get() as $plan) {
                    DB::table('installment_plans')->where('id', $plan->id)->update(['interest_percent'=>$annual * $plan->months / 12]);
                }
            }
        });
    }

    public function down(): void
    {
        // Business rates are not reverted automatically during code rollback.
    }
};
