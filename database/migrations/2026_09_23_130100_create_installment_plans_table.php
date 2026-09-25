<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T12: row-based projection of installment_systems.plans (JSON), same
 * pattern as DEC-11's motorcycle_images. Staff keep editing plans through
 * the existing Filament repeater (JSON, unchanged - the website reads it
 * as-is); InstallmentSystemObserver resyncs this table whenever `plans`
 * changes. The calculator and tools read this table, never the JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_system_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('months');
            $table->decimal('interest_percent', 6, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('installment_systems')->get() as $system) {
            $plans = json_decode($system->plans ?? '[]', true) ?: [];
            $now = now();

            $rows = collect($plans)
                ->filter(fn ($p) => isset($p['months'], $p['interest']))
                ->map(fn ($p) => [
                    'installment_system_id' => $system->id,
                    'months' => (int) $p['months'],
                    'interest_percent' => (float) $p['interest'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('installment_plans')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
