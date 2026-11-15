<?php

namespace App\Support;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * Owner 2026-11-15: the waiting customers' numbers as Excel, split over as many files as asked. When
 * the numbers do not divide evenly, the first files get one number more.
 */
class CustomerNumbersExport
{
    /** How many numbers go in each file: 10 over 3 files -> [4, 3, 3]. */
    public static function sizes(int $total, int $files): array
    {
        $files = max(1, min($files, max(1, $total)));
        $base = intdiv($total, $files);
        $extra = $total % $files;

        return array_map(fn ($i) => $base + ($i < $extra ? 1 : 0), range(0, $files - 1));
    }

    /**
     * A real phone in international digits ("201012345678"), or null for an @lid id (WhatsApp's hidden
     * number - nobody can call or message it from outside WhatsApp).
     */
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', explode('@', (string) $raw)[0]) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            return '2'.$digits;
        }

        return preg_match('/^20(10|11|12|15)\d{8}$/', $digits) ? $digits : null;
    }

    /**
     * @param  Collection<int, array{phone:string, name:?string, reason:string, last:?string}>  $rows
     * @return string path of the .xlsx (one file) or .zip (several), in a temp dir
     */
    public static function build(Collection $rows, int $files): string
    {
        $dir = storage_path('app/tmp/numbers-'.uniqid());
        @mkdir($dir, 0700, true);
        $stamp = now()->timezone('Africa/Cairo')->format('Y-m-d_H-i');
        $paths = [];
        $offset = 0;
        $sizes = self::sizes($rows->count(), $files);

        foreach ($sizes as $i => $size) {
            $path = $dir.'/'.(count($sizes) > 1 ? 'ارقام-العملاء-'.($i + 1).'-من-'.count($sizes) : 'ارقام-العملاء').'-'.$stamp.'.xlsx';
            self::writeFile($path, $rows->slice($offset, $size));
            $offset += $size;
            $paths[] = $path;
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        $zipPath = $dir.'/ارقام-العملاء-'.count($paths).'-ملفات-'.$stamp.'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($paths as $path) {
            $zip->addFile($path, basename($path));
        }
        $zip->close();
        array_map('unlink', $paths);

        return $zipPath;
    }

    private static function writeFile(string $path, Collection $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('الأرقام');
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));

        $writer->addRow(Row::fromValues(['رقم العميل', 'الاسم', 'السبب', 'آخر رسالة منه'], (new Style)->setFontBold()));

        foreach ($rows as $row) {
            // the number stays text, so Excel keeps all its digits
            $writer->addRow(Row::fromValues([(string) $row['phone'], (string) ($row['name'] ?? ''), $row['reason'], (string) ($row['last'] ?? '')]));
        }

        $writer->close();
    }
}
