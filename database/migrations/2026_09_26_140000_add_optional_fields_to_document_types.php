<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * extraction_fields must be on the document (MISSING_DATA otherwise).
 * optional_fields are read when they are printed - a salary slip's hire
 * date - and never reject the document when they are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->json('optional_fields')->nullable()->after('extraction_fields');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('optional_fields');
        });
    }
};
