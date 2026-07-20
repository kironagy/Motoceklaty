<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $modelsTable = 'gemini_api_key_models';

    public function up(): void
    {
        Schema::table('gemini_api_keys', function (Blueprint $table) {
            if (! Schema::hasColumn('gemini_api_keys', 'provider')) {
                $table->string('provider')->default('gemini')->after('id');
            }

            if (! Schema::hasColumn('gemini_api_keys', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('gemini_api_keys', 'cooldown_until')) {
                $table->timestamp('cooldown_until')->nullable()->after('last_used_at');
            }

            if (! Schema::hasColumn('gemini_api_keys', 'last_error')) {
                $table->text('last_error')->nullable()->after('cooldown_until');
            }
        });

        if (Schema::hasTable($this->modelsTable)) {
            Schema::table($this->modelsTable, function (Blueprint $table) {
                if (! Schema::hasColumn('gemini_api_key_models', 'provider')) {
                    $table->string('provider')->default('gemini')->after('gemini_api_key_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable($this->modelsTable)) {
            Schema::table($this->modelsTable, function (Blueprint $table) {
                if (Schema::hasColumn('gemini_api_key_models', 'provider')) {
                    $table->dropColumn('provider');
                }
            });
        }

        Schema::table('gemini_api_keys', function (Blueprint $table) {
            foreach (['provider', 'last_used_at', 'cooldown_until', 'last_error'] as $column) {
                if (Schema::hasColumn('gemini_api_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
