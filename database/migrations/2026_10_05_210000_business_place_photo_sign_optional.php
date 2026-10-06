<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-05: the place photo must be clear, but a sign that does not
 * read is no reason to refuse it - the tax card (required for every
 * business owner anyway) proves the business name. A name the customer
 * types in the chat never stands in for either.
 *
 * So the photo needs a clear place (business_activity, required) and the
 * sign's name is read when it can be (business_name, optional, still
 * matched against the tax card when present).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->where('key', 'business_place_photo')->update([
            'description_for_ai' => 'صورة واضحة لمكان النشاط نفسه من بره أو جوه (محل، مطعم، قهوة، كافيه، ورشة، شركة، معرض...)، والمحل مقفول أو مفتوح. '
                .'business_activity = نوع النشاط اللي باين في الصورة (قهوة، مطعم، بقالة، ورشة...) - لو الصورة مهزوزة أو ضلمة أو مش باين إنها مكان نشاط سيبه فاضي. '
                .'business_name = الاسم المكتوب على اليافطة زي ما هو (اقراه حرف حرف حتى لو نيون منور أو خط مزخرف)؛ لو اليافطة مش مقروية أو مش ظاهرة سيبه فاضي - البطاقة الضريبية هي اللي بتثبت الاسم.',
            'extraction_fields' => json_encode(['business_activity']),
            'optional_fields' => json_encode(['business_name']),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('document_types')->where('key', 'business_place_photo')->update([
            'extraction_fields' => json_encode(['business_name']),
            'optional_fields' => json_encode(['business_activity']),
            'updated_at' => now(),
        ]);
    }
};
