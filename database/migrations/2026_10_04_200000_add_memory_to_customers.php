<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Structured customer memory (ENHANCE-Ai §7-11): what the customer said about
// himself, each fact with where it came from, and every motorcycle he talked
// about with how far he went with it (asked / interested / selected...).
// One JSON column on the customer row - scoped by the customer, no new
// table or infrastructure.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'memory')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->json('memory')->nullable()->after('push_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'memory')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('memory');
            });
        }
    }
};
