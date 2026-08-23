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
}
