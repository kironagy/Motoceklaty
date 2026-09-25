<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T12: row-based projection of machines.installment_systems (JSON), same
 * pattern as installment_plans above. Staff keep editing through the
 * existing UI (JSON, unchanged); MachineObserver resyncs this pivot
 * whenever `installment_systems` changes. Machine::installmentSystems()
 * already targets this table name (app/Models/Machine.php) - it just had
 * no table to query until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_installment_system', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_system_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['machine_id', 'installment_system_id'], 'machine_installment_system_unique');
        });

        $validSystemIds = DB::table('installment_systems')->pluck('id')->all();

        foreach (DB::table('machines')->get() as $machine) {
            $ids = json_decode($machine->installment_systems ?? '[]', true) ?: [];
            $now = now();

            $rows = collect($ids)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => in_array($id, $validSystemIds, true))
                ->unique()
                ->map(fn ($id) => [
                    'machine_id' => $machine->id,
                    'installment_system_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('machine_installment_system')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_installment_system');
    }
};
