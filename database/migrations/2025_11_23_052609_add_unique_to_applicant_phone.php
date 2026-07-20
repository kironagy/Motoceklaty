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
        // قبل إضافة unique، نتحقق هل فيه أرقام مكررة ولا لأ
        $duplicates = DB::table('installment_requests')
            ->select('applicant_phone')
            ->groupBy('applicant_phone')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $table->unique('applicant_phone');
        }
    });
}



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_phone', function (Blueprint $table) {
            //
        });
    }
};
