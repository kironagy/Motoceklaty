<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard roles: a role is a named set of tabs and actions (permissions = {TabKey: [view, create, show, update, delete]}).
 * A staff member gets a role, or custom permissions of his own (staff.permissions, used instead of the role's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('finance_role')->constrained('roles')->nullOnDelete();
            $table->json('permissions')->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('permissions');
        });

        Schema::dropIfExists('roles');
    }
};
