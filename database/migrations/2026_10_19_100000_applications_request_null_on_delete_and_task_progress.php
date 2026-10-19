<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - applications.installment_request_id had no delete rule, so a request's final delete failed (1451):
 *   the application stays and only loses its link to the deleted request.
 * - daily_tasks: "جاري التنفيذ" between todo and done, with the time the member started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['installment_request_id']);
            $table->foreign('installment_request_id')->references('id')->on('installment_requests')->nullOnDelete();
        });

        if (! Schema::hasColumn('daily_tasks', 'started_at')) {
            Schema::table('daily_tasks', function (Blueprint $table) {
                $table->timestamp('started_at')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['installment_request_id']);
            $table->foreign('installment_request_id')->references('id')->on('installment_requests');
        });

        if (Schema::hasColumn('daily_tasks', 'started_at')) {
            Schema::table('daily_tasks', fn (Blueprint $table) => $table->dropColumn('started_at'));
        }
    }
};
