<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-18: a customer may apply again once his request is decided (approved, rejected,
 * canceled, delivered). The unique indexes kept one request per phone for good; the rule now
 * lives in code - only a request still waiting for a decision blocks the same phone / national id
 * (FormSubmissionService::UNDECIDED_STATUSES, the deliveries form). Plain indexes keep lookups fast.
 */
return new class extends Migration
{
    private const COLUMNS = ['applicant_phone', 'applicant_phone_2', 'applicant_national_id'];

    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('installment_requests'));

        foreach (self::COLUMNS as $column) {
            $unique = $indexes->first(fn ($i) => $i['unique'] && ! $i['primary'] && $i['columns'] === [$column]);

            Schema::table('installment_requests', function (Blueprint $table) use ($unique, $indexes, $column) {
                if ($unique) {
                    $table->dropUnique($unique['name']);
                }

                if (! $indexes->contains(fn ($i) => ! $i['unique'] && $i['columns'] === [$column])) {
                    $table->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        // not restored: rows added since may repeat a phone
    }
};
