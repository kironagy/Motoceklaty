<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff pause a submitted request and say what the customer must do
 * (resend a document, fix data, visit the branch). The request keeps the
 * choice; the application keeps what the bot must collect.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('installment_requests', 'customer_action')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->string('customer_action', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('applications', 'staff_request')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->json('staff_request')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('installment_requests', fn (Blueprint $table) => $table->dropColumn('customer_action'));
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('staff_request'));
    }
};
