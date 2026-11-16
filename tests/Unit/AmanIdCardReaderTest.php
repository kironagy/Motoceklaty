<?php

namespace Tests\Unit;

use App\Domain\Aman\IdCardReader;
use App\Domain\Documents\OcrProvider;
use App\Support\EgyptianNationalId;
use PHPUnit\Framework\TestCase;

class AmanIdCardReaderTest extends TestCase
{
    private function reader(): IdCardReader
    {
        return new IdCardReader($this->createMock(OcrProvider::class), new EgyptianNationalId());
    }

    public function test_front_gives_four_name_parts_address_and_number(): void
    {
        $front = "جمهورية مصر العربية\nبطاقة تحقيق الشخصية\nمحمد\nأحمد علي حسن السيد\nش زكي - قلعة الكبش\nالسيدة زينب - القاهرة\n٢٩٠٠١٠١١٢٣٤٥٦٧\nAB1234567";
        $out = $this->reader()->parseFront($front);

        $this->assertSame('29001011234567', $out['national_id']);
        $this->assertSame(['محمد', 'أحمد', 'علي', 'حسن السيد'], [$out['name_1'], $out['name_2'], $out['name_3'], $out['name_4']]);
        $this->assertSame('ش زكي - قلعة الكبش - السيدة زينب - القاهرة', $out['address']);
    }

    public function test_front_address_keeps_its_street_number_line(): void
    {
        // Request 5033 (Vision text): the street line has a number and used to be dropped.
        $front = "جمهورية مصر العربية\nبطاقة تحقيق الشخصية\nمصطفى\nايمن مصطفى مصطفى النجار\n٣٣ش صبحى النجار-جمال عبدالناصر\nالوراق - الجيزه\n٢٠٠٥/٠١/١٣\n٣٠٥٠١١٣٢١٠١١٥٢\nIS8105411";
        $out = $this->reader()->parseFront($front);

        $this->assertSame('30501132101152', $out['national_id']);
        $this->assertSame('مصطفى النجار', $out['name_4']);
        $this->assertSame('33ش صبحى النجار-جمال عبدالناصر - الوراق - الجيزه', $out['address']);

        // Request 5273: Vision gives Persian digits, which Aman drops.
        $out = $this->reader()->parseFront("طارق\nجمهورية مصر العربية\nبطاقة تحقيق الشخصية\nمحمد حبيب عبدالعظيم\n۱۸ الامام الشافعيش الشهداء-المنيب\nالجيزة - الجيزه\n۳۰۵۰۸۲۸۰۱۰۳۷۹۷");
        $this->assertSame('18 الامام الشافعيش الشهداء-المنيب - الجيزة - الجيزه', $out['address']);
    }

    public function test_back_gives_gender_religion_marital_and_dates(): void
    {
        $back = "٢٠٢١/٠٥\nسائق\nذكر    مسلم    متزوج\nالبطاقة سارية حتى ٢٠٢٨/٠٥/١٢";
        $out = $this->reader()->parseBack($back);

        $this->assertSame('ذكر', $out['gender']);
        $this->assertSame('مسلم', $out['religion']);
        $this->assertSame('متزوج', $out['marital_status']);
        $this->assertSame('2028-05-12', $out['expiry_date']);
        $this->assertSame('2021-05', $out['issue_date']);
    }

    public function test_female_married_reads_with_taa_marbuta(): void
    {
        $out = $this->reader()->parseBack("أنثى مسلمة متزوجة");
        $this->assertSame('أنثى', $out['gender']);
        $this->assertSame('متزوجة', $out['marital_status']);
    }

    public function test_system_name_splits_into_four(): void
    {
        $this->assertSame(
            ['name_1' => 'حسن', 'name_2' => 'محمد', 'name_3' => 'علي', 'name_4' => 'عبد الله'],
            $this->reader()->splitName('حسن محمد علي عبد الله')
        );
    }
}
