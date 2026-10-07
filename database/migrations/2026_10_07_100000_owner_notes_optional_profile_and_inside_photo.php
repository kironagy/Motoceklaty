<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner's customer notes (folder "بيانات العملاء", المطلوب.txt), checked against real customers 2026-10-07:
// - a rider's app-profile screenshot is "if available" (not every app has one): optional, asked once;
// - a shop owner without a tax card / commercial register shows the sign (the outside photo, already
//   required) AND photographs the inside of the shop: that photo counts for the tax card
//   (App\Domain\Documents\DocumentEquivalents), the way the insurance print counts for the salary slip.
return new class extends Migration
{
    public function up(): void
    {
        $profile = DB::table('document_types')->where('key', 'delivery_app_profile')->value('id');

        if ($profile) {
            DB::table('application_requirements')->where('document_type_id', $profile)->update(['is_required' => false, 'updated_at' => now()]);
        }

        if (! DB::table('document_types')->where('key', 'business_place_inside_photo')->exists()) {
            $place = DB::table('document_types')->where('key', 'business_place_photo')->first();

            DB::table('document_types')->insert([
                'key' => 'business_place_inside_photo',
                'label' => 'صورة المحل من جوا',
                'description_for_ai' => 'صورة من جوا المحل / المطعم / الورشة / القهوة (الكاونتر، الرفوف، المعدات، البضاعة) - مش اليافطة ولا الواجهة من بره.',
                'accepted_mimes' => $place->accepted_mimes ?? json_encode(['image/jpeg', 'image/png']),
                'extraction_fields' => json_encode([]),
                'optional_fields' => json_encode([]),
                'validation_rules' => json_encode([]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $profile = DB::table('document_types')->where('key', 'delivery_app_profile')->value('id');

        if ($profile) {
            DB::table('application_requirements')->where('document_type_id', $profile)->update(['is_required' => true]);
        }

        DB::table('document_types')->where('key', 'business_place_inside_photo')->delete();
    }
};
