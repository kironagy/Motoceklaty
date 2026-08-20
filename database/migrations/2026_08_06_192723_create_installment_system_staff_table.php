<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * لو الجدول مش موجود، ننشئه.
         */
        if (! Schema::hasTable('installment_system_staff')) {

            Schema::create('installment_system_staff', function (Blueprint $table) {
                $table->id();

                $table->foreignId('staff_id')
                    ->constrained('staff')
                    ->cascadeOnDelete();

                $table->foreignId('installment_system_id')
                    ->constrained('installment_systems')
                    ->cascadeOnDelete();

                $table->timestamps();

                $table->unique([
                    'staff_id',
                    'installment_system_id'
                ]);
            });

        } else {

            /*
             * الجدول موجود بالفعل:
             * نضيف الأعمدة الناقصة فقط.
             */
            if (! Schema::hasColumn('installment_system_staff', 'staff_id')) {
                Schema::table('installment_system_staff', function (Blueprint $table) {
                    $table->foreignId('staff_id')
                        ->nullable()
                        ->constrained('staff')
                        ->cascadeOnDelete();
                });
            }

            if (! Schema::hasColumn('installment_system_staff', 'installment_system_id')) {
                Schema::table('installment_system_staff', function (Blueprint $table) {
                    $table->foreignId('installment_system_id')
                        ->nullable()
                        ->constrained('installment_systems')
                        ->cascadeOnDelete();
                });
            }
        }

        /*
         * ننقل الشركة القديمة للـ Multi Select
         */
        if (Schema::hasColumn('staff', 'installment_system_id')) {

            $staffMembers = DB::table('staff')
                ->whereNotNull('installment_system_id')
                ->get();

            foreach ($staffMembers as $staff) {

                DB::table('installment_system_staff')
                    ->updateOrInsert(
                        [
                            'staff_id' => $staff->id,
                            'installment_system_id' => $staff->installment_system_id,
                        ],
                        [
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_system_staff');
    }
};
