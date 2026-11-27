<?php

namespace App\Domain\Asfoora;

use Illuminate\Support\Facades\Storage;

/**
 * Owner 2026-11-26: a sheet he gives «عصفورة» in the chat (Excel or CSV). Read with OpenSpout;
 * each sheet's first row that looks like a header names the columns.
 */
class SheetFile
{
    public const DISK = 'local';

    /** @return array<string, array{headers: list<string>, rows: list<array<string, mixed>>}> sheet name => rows */
    public static function read(string $path): array
    {
        $full = Storage::disk(self::DISK)->path($path);
        $reader = str_ends_with(strtolower($path), '.csv') ? new \OpenSpout\Reader\CSV\Reader() : new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($full);
        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rows = [];
                // live 2026-11-27: his sheet has a title and totals above the header (row 4) - the
                // header is the row of the first ones with the most text cells
                $top = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_string($v) ? trim($v) : $v), $row->toArray());
                    if (array_filter($cells, fn ($v) => $v !== null && $v !== '') === []) {
                        continue;
                    }
                    if ($headers === null && count($top) < self::HEADER_SEARCH) {
                        $top[] = $cells;

                        continue;
                    }
                    if ($headers === null) {
                        $headers = self::headerFrom($top, $rows);
                    }
                    $rows[] = self::combine($headers, $cells);
                    if (count($rows) >= 20000) {
                        break;
                    }
                }
                if ($headers === null && $top !== []) {
                    $headers = self::headerFrom($top, $rows);
                }
                $sheets[(string) $sheet->getName()] = ['headers' => $headers ?? [], 'rows' => $rows];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    private const HEADER_SEARCH = 10;

    /** @param list<array> $top the first rows; the rows after the header go into $rows */
    private static function headerFrom(array $top, array &$rows): array
    {
        $text = fn (array $cells) => count(array_filter($cells, fn ($v) => is_string($v) && $v !== '' && ! str_starts_with($v, '=') && ! is_numeric($v)));
        $at = 0;
        foreach ($top as $i => $cells) {
            if ($text($cells) > $text($top[$at])) {
                $at = $i;
            }
        }
        $headers = [];
        foreach ($top[$at] as $i => $h) {
            $name = trim((string) $h) !== '' ? preg_replace('/\s+/u', ' ', trim((string) $h)) : 'عمود '.($i + 1);
            $headers[] = in_array($name, $headers, true) ? $name.' '.($i + 1) : $name;
        }
        $after = array_map(fn ($cells) => self::combine($headers, $cells), array_slice($top, $at + 1));
        array_unshift($rows, ...$after);

        return $headers;
    }

    private static function combine(array $headers, array $cells): array
    {
        return array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), null));
    }

    /** "احمد  عبدالموجود" / "أحمد عبد الموجود" → one spelling, for matching names */
    public static function normalizeName(?string $name): string
    {
        $n = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', (string) $name);
        $n = strtr($n, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $n = preg_replace('/عبد\s+/u', 'عبد', $n);
        $n = preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $n);

        return trim(preg_replace('/\s+/u', ' ', $n));
    }
}
