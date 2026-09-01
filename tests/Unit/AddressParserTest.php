<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Support\AddressParser;

/**
 * Reported production crash: "الدور التاني امام جامع الشيخ احمد" made
 * the whole turn fail silently (no reply ever reached the customer).
 * Root cause: trim($m[1], " .،") used a multi-byte Arabic character in
 * trim()'s byte-mask argument, which strips individual UTF-8 bytes and
 * can slice through an adjacent multi-byte character, producing a string
 * that's no longer valid UTF-8 - json_encode() (used to persist
 * context_payload) then throws and the whole request aborts silently
 * from the customer's point of view.
 */
class AddressParserTest extends TestCase
{
    public function test_floor_value_after_ordinal_word_is_valid_utf8(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('الدور التاني امام جامع الشيخ احمد');

        $this->assertSame('التاني', $components['floor']);
        $this->assertTrue(mb_check_encoding($components['floor'], 'UTF-8'));

        // The whole components array must be safely JSON-encodable -
        // this is the exact operation that crashed in production.
        $this->assertIsString(json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function test_apartment_value_stays_valid_utf8(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('شقة الاخيرة في الدور');

        $this->assertTrue(mb_check_encoding((string) $components['apartment'], 'UTF-8'));
    }

    public function test_landmark_is_still_extracted(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('الدور التاني امام جامع الشيخ احمد');

        $this->assertSame('جامع الشيخ احمد', $components['landmark']);
    }

    /**
     * محادثة حقيقية: "اكتوبر ١٥ أ - مربع ٣ - فيله ١١٥" ردّ عليها البوت
     * بـ"لسه محتاج رقم العمارة والدور ورقم الشقة وعلامة مميزة"، والعميل
     * رد "فيلا ١١٥"، فالبوت أعاد نفس الجملة حرفيًا. الفيلا مالهاش دور
     * ولا رقم شقة، ورقمها هو رقم العقار.
     */
    public function test_villa_number_is_the_building_number(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('فيلا ١١٥');

        $this->assertSame('115', $components['building']);
        $this->assertSame('villa', $components['residence_type']);
    }

    public function test_villa_is_not_asked_for_a_floor_or_an_apartment(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('اكتوبر ١٥ أ  - مربع ٣ - فيله ١١٥');

        $this->assertSame('115', $components['building']);
        $this->assertSame('مربع ٣', $components['area']);

        $missing = $parser->status($components, true)['missing'];

        $this->assertNotContains('floor', $missing);
        $this->assertNotContains('apartment', $missing);
        $this->assertSame(['landmark', 'ownership'], $missing);
    }

    /**
     * محادثة حقيقية: العميل بعت "اكتوبر ١٢ شارع محمد سيد شقه ٢ الدور
     * التاني امام سوبر ماركت المحبه" والبوت طلب منه رقم العمارة، فرد
     * "ماهو ١٢ ده رقم العمارة". الرقم اللي قبل كلمة "شارع" هو رقم
     * العمارة، بس اسم المدينة قبله كان بيمنع قراءته.
     */
    public function test_building_number_before_the_street_survives_a_city_name(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('اكتوبر ١٢ شارع محمد سيد شقه ٢ الدور التاني امام سوبر ماركت المحبه');

        $this->assertSame('12', $components['building']);
        $this->assertSame('محمد سيد', $components['street']);
        $this->assertSame('2', $components['apartment']);
        $this->assertSame('التاني', $components['floor']);
        $this->assertSame('سوبر ماركت المحبه', $components['landmark']);
        $this->assertSame(['ownership'], $parser->status($components, true)['missing']);
    }

    /**
     * اسم الشارع كان بياخد كل اللي بعده لآخر السطر، فباقي مكوّنات
     * العنوان كانت بتتحشر جواه.
     */
    public function test_street_name_stops_at_the_next_component(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('المهندسين شارع جامعة الدول الدور الرابع شقه ١٢ قدام بنك مصر ايجار');

        $this->assertSame('جامعه الدول', $components['street']);
        $this->assertSame('الرابع', $components['floor']);
        $this->assertSame('بنك مصر', $components['landmark']);
        $this->assertSame('إيجار', $components['ownership']);
    }

    /**
     * "شارع الملك فيصل" مش معناها إن السكن ملك - كلمة "ملك" لازم
     * تتطابق كلمة كاملة، وإلا بنسجّل في الطلب جواب العميل مقالوش.
     */
    public function test_king_faisal_street_is_not_read_as_owned_housing(): void
    {
        $parser = new AddressParser();
        $components = $parser->parse('شارع الملك فيصل عماره ٧ الدور ٢ شقه ٣ امام صيدليه العزبي');

        $this->assertNull($components['ownership']);
        $this->assertSame('الملك فيصل', $components['street']);
        $this->assertSame('7', $components['building']);
    }
}
