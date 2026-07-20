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
    Schema::table('staff', function (Blueprint $table) {
        $table->boolean('is_super_admin')
              ->default(false)
              ->after('is_admin');
    });
}

public function down()
{
    Schema::table('staff', function (Blueprint $table) {
        $table->dropColumn('is_super_admin');
    });
}

};
