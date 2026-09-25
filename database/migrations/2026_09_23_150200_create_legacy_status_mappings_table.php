<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-21 (agreed 2026-09-23): a dashboard-editable mapping, not a hardcoded
 * switch. Only 'approved' and 'rejected' share an unambiguous meaning with
 * the new Application domain and are pre-filled; every other legacy status
 * ('new_request', 'new', 'pending', 'work_check', 'paused', 'transferred',
 * 'delivered', 'canceled') is a staff-internal workflow stage whose exact
 * meaning isn't determinable from code alone, so it is left NULL
 * (NEEDS_DECISION) rather than guessed - InstallmentRequestObserver must
 * not silently invent a mapping for these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_status_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_status')->unique();
            $table->string('application_status')->nullable();
            $table->timestamps();
        });

        $now = now();
        $legacyStatuses = [
            'new_request', 'new', 'pending', 'work_check', 'approved',
            'rejected', 'paused', 'transferred', 'delivered', 'canceled',
        ];
        $known = ['approved' => 'approved', 'rejected' => 'rejected'];

        DB::table('legacy_status_mappings')->insert(array_map(fn ($legacy) => [
            'legacy_status' => $legacy,
            'application_status' => $known[$legacy] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $legacyStatuses));
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_status_mappings');
    }
};
