<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-10-07: "رقمي X ولو مردتش ده رقم تاني Y" - the second number was lost. It is a field of
// its own now (never required), saved from his message and put on the request (applicant_phone_2).
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('requirement_fields')->where('key', 'phone_2')->exists()) {
            DB::table('requirement_fields')->insert([
                'key' => 'phone_2',
                'label' => 'رقم تليفون تاني',
                'data_type' => 'phone',
                'scope' => 'application',
                'is_sensitive' => false,
                'description_for_ai' => 'A second number he gave to reach him (not a guarantor\'s).',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('requirement_fields')->where('key', 'phone_2')->delete();
    }
};
