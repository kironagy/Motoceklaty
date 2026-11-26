<?php

namespace App\Domain\Asfoora;

use App\Models\AsfooraMessage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Owner 2026-11-26: an answer of «عصفورة» downloads as a tidy Excel file - right to left, a title
 * band with the question and the time, teal headers, banded rows, borders, frozen header, columns
 * sized to their text. One sheet per table the answer was built from (the last one first).
 */
class AsfooraExcel
{
    /** Arabic headers for the tools' own keys (run_sql's columns keep the names the AI gave them) */
    private const HEADERS = [
        'id' => 'رقم الطلب', 'customer' => 'العميل', 'phone' => 'التليفون', 'phone_2' => 'تليفون تاني',
        'status' => 'الحالة', 'staff' => 'الموظف', 'system' => 'النظام', 'machine' => 'المكنة', 'months' => 'المدة (شهر)',
        'reason' => 'السبب', 'notes' => 'ملاحظات', 'created_at' => 'تاريخ الرفع', 'status_updated_at' => 'آخر تغيير للحالة',
        'uploaded_by' => 'رفعه', 'owners' => 'اتنقل بين (الأقدم ← الأحدث)', 'taken_from' => 'السيلز اللي اتسحب منه الطلب',
        'current_staff' => 'السيلز الحالي (حسب اللوحة)', 'still_with_employee' => 'لسه مع موظفه؟', 'aman_reason' => 'سبب الحالة (كلام أمان)',
        'staff_note' => 'ملاحظة الموظف', 'verdict' => 'ليه في المجموعة دي', 'group' => 'البند', 'previous' => 'الفترة اللي قبلها', 'change' => 'الفرق', 'count' => 'العدد', 'at' => 'الوقت', 'by' => 'بواسطة', 'what' => 'اللي حصل', 'details' => 'التفاصيل',
    ];

    /**
     * The AI saw part of a list (50-300 rows); the file gets all of it: the same list or query is run
     * again without that cap. If that fails, the rows the answer was made from are used.
     */
    private static function complete(array $table): array
    {
        $tool = $table['source']['tool'] ?? null;
        $args = (array) ($table['source']['args'] ?? []);

        try {
            if ($tool === 'list_requests' || $tool === 'requests_with_history') {
                $rows = $tool === 'list_requests' ? AsfooraTools::exportList($args) : AsfooraTools::exportHistory($args);

                // a sorted group keeps the AI's note on each request
                $rows = AsfooraTools::withNotes($rows, (array) ($table['notes'] ?? []));
                $columns = ($table['group'] ?? false) || ! isset($table['columns']) ? array_keys($rows[0] ?? []) : $table['columns'];

                return ['rows' => $rows, 'columns' => $columns, 'fresh' => true, 'truncated' => count($rows) >= AsfooraTools::EXPORT_ROWS] + $table;
            }
            if ($tool === 'run_sql') {
                $result = ReadOnlyQuery::run((string) ($args['query'] ?? ''), AsfooraTools::EXPORT_ROWS, 30000);

                return ['rows' => $result['rows'], 'columns' => $result['columns'] ?: ($table['columns'] ?? []), 'fresh' => true, 'truncated' => $result['truncated']] + $table;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $table;
    }

    /** a row matching one of the AI's colour rules ("status = متوقف امان → yellow") gets that colour */
    private static function paint(array $row, array $rules, Border $line): ?Style
    {
        $colors = ['yellow' => 'FEF08A', 'red' => 'FECACA', 'green' => 'BBF7D0', 'orange' => 'FED7AA', 'blue' => 'BFDBFE'];
        foreach ($rules as $rule) {
            $value = $row[$rule['column'] ?? ''] ?? null;
            if ($value !== null && trim((string) $value) === trim((string) ($rule['value'] ?? '')) && isset($colors[$rule['color'] ?? ''])) {
                return (new Style())->setBorder($line)->setBackgroundColor($colors[$rule['color']])
                    ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setShouldWrapText()->setFontName('Cairo');
            }
        }

        return null;
    }

    public static function filename(AsfooraMessage $message, ?int $only = null): string
    {
        $title = $only !== null ? (string) ($message->meta['tables'][$only]['title'] ?? '') : '';
        $title = trim(preg_replace('~[\\\\/:*?"<>|]+~u', ' ', $title));

        return ($title !== '' ? $title.' - ' : 'asfoora-').$message->created_at->format('Y-m-d-His').'.xlsx';
    }

    /** Writes the file and returns its path (a temp file the caller deletes after sending). */
    /** @param  int|null  $only  one table (a sorted group) as its own file; null = every table, a sheet each */
    public static function write(AsfooraMessage $message, string $question, ?int $only = null): string
    {
        $tables = array_values($message->meta['tables'] ?? []);
        if ($only !== null) {
            $tables = isset($tables[$only]) ? [$tables[$only]] : [];
        }
        $path = tempnam(sys_get_temp_dir(), 'asf').'.xlsx';

        $options = new Options();
        // printed: landscape A4, every column on one page wide
        $options->setPageSetup(new \OpenSpout\Writer\XLSX\Options\PageSetup(\OpenSpout\Writer\XLSX\Options\PageOrientation::LANDSCAPE, \OpenSpout\Writer\XLSX\Options\PaperSize::A4, 0, 1));
        $writer = new Writer($options);
        $writer->openToFile($path);

        $teal = '0D9488';
        $line = new Border(
            new BorderPart(Border::TOP, 'CBD5E1', Border::WIDTH_THIN),
            new BorderPart(Border::BOTTOM, 'CBD5E1', Border::WIDTH_THIN),
            new BorderPart(Border::LEFT, 'CBD5E1', Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, 'CBD5E1', Border::WIDTH_THIN),
        );
        $title = (new Style())->setFontBold()->setFontSize(16)->setFontColor('FFFFFF')->setBackgroundColor('0F766E')
            ->setCellAlignment(CellAlignment::RIGHT)->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setFontName('Cairo');
        $sub = (new Style())->setFontSize(10)->setFontColor('475569')->setBackgroundColor('CCFBF1')->setCellAlignment(CellAlignment::RIGHT)->setFontName('Cairo');
        $head = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor($teal)->setBorder($line)
            ->setCellAlignment(CellAlignment::CENTER)->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setShouldWrapText()->setFontName('Cairo');
        $even = (new Style())->setBorder($line)->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setShouldWrapText()->setFontName('Cairo');
        $odd = (new Style())->setBorder($line)->setBackgroundColor('F0FDFA')->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setShouldWrapText()->setFontName('Cairo');
        $totalStyle = (new Style())->setFontBold()->setBorder($line)->setBackgroundColor('99F6E4')->setFontName('Cairo');

        if ($tables === []) {
            $tables = [['title' => 'الرد', 'columns' => ['الرد'], 'rows' => [['الرد' => $message->content]]]];
        }

        foreach ($tables as $i => $table) {
            $table = self::complete($table);
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', (string) ($table['title'] ?? 'جدول')), 0, 28).($i ? ' '.($i + 1) : ''));
            $sheet->setSheetView((new SheetView())->setRightToLeft(true)->setShowGridLines(false)->setFreezeRow(4));

            // the "still with his employee" flag is said by «اتسحب من» already - the sheet stays readable
            $columns = array_values(array_diff($table['columns'] ?? array_keys($table['rows'][0] ?? []), ['still_with_employee']));
            $headers = array_map(fn ($c) => self::HEADERS[$c] ?? str_replace('_', ' ', (string) $c), $columns);
            $width = max(1, count($columns));

            $writer->addRow(Row::fromValues([mb_substr('عصفورة — '.$question, 0, 250)], $title)->setHeight(34));
            $writer->addRow(Row::fromValues([($table['title'] ?? '').' · '.count($table['rows'] ?? []).' صف'
                .(($table['fresh'] ?? false) ? ' · كل البيانات وقت التنزيل '.now()->format('Y-m-d H:i') : ' · '.$message->created_at->format('Y-m-d H:i'))
                .(($table['truncated'] ?? false) ? ' · أول '.AsfooraTools::EXPORT_ROWS.' صف بس' : '')], $sub)->setHeight(20));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues($headers, $head)->setHeight(26));
            $writer->getOptions()->mergeCells(0, 1, $width - 1, 1, $i);
            $writer->getOptions()->mergeCells(0, 2, $width - 1, 2, $i);

            $lengths = array_map(fn ($h) => mb_strlen($h), $headers);
            foreach (array_values($table['rows'] ?? []) as $n => $row) {
                $values = [];
                foreach ($columns as $c => $column) {
                    $value = $row[$column] ?? null;
                    $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'أيوه' : 'لأ') : $value);
                    $value = $value === null || $value === '' ? '-' : $value;
                    // phones and national ids stay text (Excel would drop the leading 0)
                    if (is_string($value) && is_numeric($value) && ! preg_match('/^0\d/', $value) && strlen($value) < 12) {
                        $value = $value + 0;
                    }
                    $values[] = $value;
                    $lengths[$c] = max($lengths[$c], min(60, mb_strlen((string) $value)));
                }
                $writer->addRow(Row::fromValues($values, self::paint($row, (array) ($table['highlight'] ?? []), $line) ?? ($n % 2 ? $odd : $even)));
            }

            // a count column gets its total
            $countAt = array_search('count', $columns, true);
            if ($countAt !== false) {
                $cells = array_fill(0, $width, '');
                $cells[0] = 'الإجمالي';
                $cells[$countAt] = array_sum(array_map(fn ($r) => (int) ($r['count'] ?? 0), $table['rows'] ?? []));
                $writer->addRow(Row::fromValues($cells, $totalStyle));
            }

            foreach ($lengths as $c => $len) {
                $sheet->setColumnWidth(max(10, min(55, $len * 1.25 + 4)), $c + 1);
            }
        }

        $writer->close();

        return $path;
    }
}
