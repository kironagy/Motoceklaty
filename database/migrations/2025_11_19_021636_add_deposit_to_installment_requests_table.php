<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->decimal('deposit', 10, 2)->default(0);
    });
}

public function down()
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->dropColumn('deposit');
    });
}

};
