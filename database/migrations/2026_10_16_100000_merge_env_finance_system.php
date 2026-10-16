<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merges the finance / showroom project (env.motocyklaty.store) into this dashboard.
 *
 * - Logins stay on `staff`; env `users` become staff rows, so every env `user_id` is `staff_id` here.
 * - Finance permissions live in `staff.finance_role` (employee/admin/super_admin), separate from the
 *   bot-dashboard flags, so a bot super admin never sees every showroom's treasury by accident.
 * - The old, unused staff attendance table (Jan-May 2026) is kept as `attendances_legacy`.
 * - Columns keep the env nullability: what env stores NOT NULL stays required here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'checked_in_at')) {
            Schema::rename('attendances', 'attendances_legacy');
            // FK names are unique per database: free `attendances_staff_id_foreign` for the new table.
            Schema::table('attendances_legacy', function (Blueprint $table) {
                $table->dropForeign('attendances_staff_id_foreign');
                $table->foreign('staff_id', 'attendances_legacy_staff_id_foreign')->references('id')->on('staff')->cascadeOnDelete();
            });
        }

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Optional link to the public branch of the same showroom; expense centres have none.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->string('finance_role')->nullable()->after('is_company_employee');
            $table->foreignId('warehouse_id')->nullable()->after('finance_role')->constrained('warehouses')->nullOnDelete();
            $table->boolean('is_showroom_employee')->default(false)->after('warehouse_id');
            $table->decimal('monthly_salary', 10, 2)->nullable()->after('is_showroom_employee');
            $table->time('attendance_time')->nullable()->after('monthly_salary');
            $table->time('departure_time')->nullable()->after('attendance_time');
            $table->decimal('attendance_latitude', 10, 7)->nullable()->after('departure_time');
            $table->decimal('attendance_longitude', 10, 7)->nullable()->after('attendance_latitude');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Optional link to the catalogue machine; set only when the names really match.
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->string('chassis_image');
            $table->string('engine_image');
            $table->string('color');
            $table->string('customer_name')->nullable();
            $table->string('national_id')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('customer_id_image')->nullable();
            $table->decimal('cash', 10, 2)->nullable();
            $table->decimal('limit', 10, 2)->nullable();
            $table->decimal('admin_fees', 10, 2)->nullable();
            $table->decimal('paid', 10, 2)->nullable();
            $table->decimal('remaining', 10, 2)->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->timestamps();
        });

        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->nullable()->constrained('inventories')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('chassis_image')->nullable();
            $table->string('engine_image')->nullable();
            $table->string('color')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('national_id')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('customer_id_image')->nullable();
            $table->decimal('cash', 12, 2)->nullable();
            $table->decimal('limit', 12, 2)->nullable();
            $table->decimal('admin_fees', 12, 2)->nullable();
            $table->decimal('paid', 12, 2)->nullable();
            $table->decimal('remaining', 12, 2)->nullable();
            $table->boolean('is_returned')->default(false);
            $table->timestamp('returned_at')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('status')->default('لم يتم التسليم');
            $table->timestamps();
        });

        Schema::create('treasury_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->date('expense_date');
            $table->timestamps();
            $table->index(['warehouse_id', 'expense_date']);
        });

        Schema::create('treasury_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('treasury_expense_id')->constrained()->cascadeOnDelete();
            $table->string('expense_item');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->integer('target_count')->default(0);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_deduction_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedInteger('from_minute');
            $table->unsignedInteger('to_minute');
            $table->decimal('deduction_days', 4, 2);
            $table->timestamps();
            $table->index(['staff_id', 'from_minute', 'to_minute']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->decimal('deduction_days', 4, 2)->default(0);
            $table->decimal('deduction_amount', 10, 2)->default(0);
            $table->string('status')->default('present');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['staff_id', 'attendance_date']);
            $table->index(['attendance_date', 'staff_id']);
        });

        Schema::create('employee_advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('request_date');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'status', 'request_date'], 'adv_req_staff_status_date_idx');
        });

        Schema::create('employee_vacation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('vacation_type')->default('normal');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'status'], 'vac_req_staff_status_idx');
            $table->index(['from_date', 'to_date'], 'vac_req_dates_idx');
        });

        Schema::create('employee_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('bonus_date');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['staff_id', 'bonus_date'], 'emp_bonus_staff_date_idx');
        });

        // Old env id -> new id here, so the import can run again without duplicating anything.
        Schema::create('env_import_map', function (Blueprint $table) {
            $table->string('table', 64);
            $table->unsignedBigInteger('env_id');
            $table->unsignedBigInteger('local_id');
            $table->timestamps();
            $table->primary(['table', 'env_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'env_import_map', 'employee_bonuses', 'employee_vacation_requests', 'employee_advance_requests',
            'attendances', 'attendance_deduction_rules', 'targets', 'treasury_expense_items', 'treasury_expenses',
            'letters', 'inventory_items', 'inventories', 'products',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn([
                'finance_role', 'is_showroom_employee', 'monthly_salary', 'attendance_time',
                'departure_time', 'attendance_latitude', 'attendance_longitude',
            ]);
        });

        Schema::dropIfExists('warehouses');

        if (Schema::hasTable('attendances_legacy')) {
            Schema::table('attendances_legacy', function (Blueprint $table) {
                $table->dropForeign('attendances_legacy_staff_id_foreign');
                $table->foreign('staff_id', 'attendances_staff_id_foreign')->references('id')->on('staff')->cascadeOnDelete();
            });
            Schema::rename('attendances_legacy', 'attendances');
        }
    }
};
