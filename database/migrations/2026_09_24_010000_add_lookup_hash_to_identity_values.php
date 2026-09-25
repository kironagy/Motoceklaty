<?php

use App\Models\ApplicationData;
use App\Models\CustomerAttribute;
use App\Support\IdentityLookup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Identity values (national IDs) are encrypted at rest, so
 * "is this ID already on another customer?" could not be asked of the DB
 * at all - one customer's ID was accepted onto another customer's
 * application. A keyed hash of the normalized value makes it queryable
 * without ever storing the value in clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_attributes', 'application_data'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('lookup_hash', 64)->nullable()->index();
            });
        }

        // Backfill through the models so the encrypted cast decrypts.
        foreach ([CustomerAttribute::class, ApplicationData::class] as $model) {
            $model::query()->whereIn('field_key', IdentityLookup::identityFieldKeys())->each(function ($row) {
                $row->forceFill(['lookup_hash' => IdentityLookup::hash((string) $row->value)])->saveQuietly();
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_attributes', 'application_data'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex([ 'lookup_hash' ]);
                $t->dropColumn('lookup_hash');
            });
        }
    }
};
