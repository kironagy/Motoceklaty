<?php

namespace App\Support;

use ZipArchive;

class WorkFactoryExport
{
    public static function split(array $rows, int $parts): array
    {
        if ($parts < 1 || $parts > 100 || $parts > count($rows)) {
            throw new \InvalidArgumentException('عدد الملفات لازم يكون بين 1 و100 ولا يزيد عن عدد العملاء.');
        }
        $result = []; $offset = 0;
        for ($i = 0; $i < $parts; $i++) {
            $size = intdiv(count($rows), $parts) + ($i < count($rows) % $parts ? 1 : 0);
            $result[] = array_slice($rows, $offset, $size); $offset += $size;
        }
        return $result;
    }

    public static function workbook(array $rows, string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('تعذر إنشاء ملف Excel.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="العملاء" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="4" width="28" customWidth="1"/></cols><sheetData>';
        $all = [['رقم الهاتف', 'اسم العميل', 'اليوزرنيم', 'المعرف المتاح']];
        foreach ($rows as $row) $all[] = [$row['phone'] ?? '', $row['name'] ?? '', $row['username'] ?? '', $row['identifier'] ?? ''];
        foreach ($all as $i => $values) {
            $xml .= '<row r="'.($i + 1).'">';
            foreach ($values as $j => $value) {
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';
                // Explicit string cells preserve phone zeros and cannot execute formulas.
                $xml .= '<c r="'.chr(65 + $j).($i + 1).'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:D'.count($all).'"/></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        if (! $zip->close()) throw new \RuntimeException('تعذر حفظ ملف Excel.');
    }
}
