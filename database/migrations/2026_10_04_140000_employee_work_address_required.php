<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner's file (بيانات العملاء/موظف): "عنوان العمل وعنوان السكن بالتفصيل" for
// an employee - the bot never asked an employee where he works, and the
// dashboard's work address stayed empty. Same three fields as a shop owner.
return new class extends Migration
{
    public function up(): void
    {
        $employee = DB::table('customer_types')->where('key', 'employee')->value('id');

        foreach (['work_address' => 8, 'work_building_no' => 8, 'work_landmark' => 9] as $key => $sort) {
            $field = DB::table('requirement_fields')->where('key', $key)->value('id');

            if ($employee && $field && ! DB::table('application_requirements')->where('customer_type_id', $employee)->where('requirement_field_id', $field)->exists()) {
                DB::table('application_requirements')->insert([
                    'customer_type_id' => $employee, 'requirement_type' => 'field', 'requirement_field_id' => $field,
                    'document_type_id' => null, 'is_required' => true, 'condition' => null, 'sort' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('requirement_fields')->where('key', 'residence_ownership')
            ->update(['description_for_ai' => 'owned = تمليك / ملك / ملكي / شقتي، rented = إيجار / مأجرة، family = ساكن مع أهله (بيت العيلة).']);
    }

    public function down(): void
    {
        $employee = DB::table('customer_types')->where('key', 'employee')->value('id');
        $fields = DB::table('requirement_fields')->whereIn('key', ['work_address', 'work_building_no', 'work_landmark'])->pluck('id');
        DB::table('application_requirements')->where('customer_type_id', $employee)->whereIn('requirement_field_id', $fields)->delete();
    }
};
