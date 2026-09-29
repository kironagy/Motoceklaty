<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner (2026-09-29): every job gets its full list of documents, none
 * skipped. The list per job:
 * - employee: ID front + back, salary slip (unchanged);
 * - self-employed craftsman / other: ID front + back (unchanged);
 * - delivery app (طلبات/أوبر...): ID, driving license, 3-month earnings
 *   screenshot and now the app profile screenshot too;
 * - business owner: ID, business place photo and now the tax card /
 *   commercial register, required instead of optional.
 * Idempotent: rows are matched by key.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Configuration rows only exist on a seeded database (live/local).
        $selfEmployed = DB::table('customer_types')->where('key', 'self_employed')->value('id');
        $businessOwner = DB::table('customer_types')->where('key', 'business_owner')->value('id');
        $profile = DB::table('document_types')->where('key', 'delivery_app_profile')->value('id');
        $earnings = DB::table('document_types')->where('key', 'delivery_app_earnings')->value('id');
        $taxCard = DB::table('document_types')->where('key', 'tax_card')->value('id');
        $now = now();

        if ($selfEmployed && $profile && ! DB::table('application_requirements')->where('customer_type_id', $selfEmployed)->where('document_type_id', $profile)->exists()) {
            $earningsRow = DB::table('application_requirements')->where('customer_type_id', $selfEmployed)->where('document_type_id', $earnings)->first();

            DB::table('application_requirements')->insert([
                'customer_type_id' => $selfEmployed,
                'requirement_type' => 'document',
                'document_type_id' => $profile,
                'is_required' => true,
                'condition' => $earningsRow->condition ?? json_encode(['op' => 'in', 'fact' => 'work_type', 'value' => ['delivery_app', 'delivery_app_bicycle']]),
                'sort' => ($earningsRow->sort ?? 0) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($businessOwner && $taxCard) {
            DB::table('application_requirements')->where('customer_type_id', $businessOwner)->where('document_type_id', $taxCard)
                ->update(['is_required' => true, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $selfEmployed = DB::table('customer_types')->where('key', 'self_employed')->value('id');
        $businessOwner = DB::table('customer_types')->where('key', 'business_owner')->value('id');

        DB::table('application_requirements')->where('customer_type_id', $selfEmployed)
            ->where('document_type_id', DB::table('document_types')->where('key', 'delivery_app_profile')->value('id'))->delete();
        DB::table('application_requirements')->where('customer_type_id', $businessOwner)
            ->where('document_type_id', DB::table('document_types')->where('key', 'tax_card')->value('id'))->update(['is_required' => false]);
    }
};
