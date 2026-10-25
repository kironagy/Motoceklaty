<?php

namespace Tests\Unit;

use App\Support\WorkFactoryExport;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class WorkFactoryExportTest extends TestCase
{
    public function test_balanced_split_has_no_loss_or_duplicates(): void
    {
        foreach ([[300, 2, [150, 150]], [301, 2, [151, 150]], [7, 3, [3, 2, 2]]] as [$count, $parts, $sizes]) {
            $rows = range(1, $count); $chunks = WorkFactoryExport::split($rows, $parts);
            $this->assertSame($sizes, array_map('count', $chunks));
            $this->assertSame($rows, array_merge(...$chunks));
        }
    }

    public function test_rejects_more_files_than_rows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkFactoryExport::split([1], 2);
    }

    public function test_excel_preserves_phone_as_text_and_never_creates_formulas(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'factory-test-');
        try {
            WorkFactoryExport::workbook([['phone' => '00123456789', 'name' => '=HYPERLINK("test")', 'username' => 'اسم & عربي', 'identifier' => 'alias']], $file);
            $zip = new ZipArchive; $this->assertTrue($zip->open($file));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertNotFalse(simplexml_load_string($xml));
            $this->assertStringContainsString('00123456789', $xml);
            $this->assertStringContainsString('t="inlineStr"', $xml);
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertStringNotContainsString('اسم العميل', $xml); $this->assertStringNotContainsString('HYPERLINK', $xml); $zip->close();
        } finally { @unlink($file); }
    }
}
