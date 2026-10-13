<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website-only presentation fields on tables the WhatsApp agent also reads.
 * All nullable / defaulted so nothing on the agent side changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reviews', function (Blueprint $table) {
            $table->string('position')->nullable()->after('name');
            $table->string('image')->nullable()->after('position');
            $table->unsignedTinyInteger('rating')->default(5)->after('review');
            $table->boolean('is_active')->default(true)->after('rating');
            $table->unsignedInteger('sort')->default(0)->after('is_active');
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_active');
            $table->string('video_url')->nullable()->after('specifications');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('web_icon')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('client_reviews', function (Blueprint $table) {
            $table->dropColumn(['position', 'image', 'rating', 'is_active', 'sort']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'video_url']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('web_icon');
        });
    }
};
