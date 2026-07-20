<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->enum('type', ['normal', 'offer'])->default('normal')->after('brand_id');
            $table->decimal('old_price', 10, 2)->nullable()->after('installment_price');
            $table->decimal('new_price', 10, 2)->nullable()->after('old_price');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['type', 'old_price', 'new_price']);
        });
    }
};
