<?php

namespace Cmd\Reports\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

/** Read the saved totals from an identified legacy workbook; this never verifies pricing or pays anyone. */
final class LegacyRetentionWorkbook
{
    public static function read(string $path, string $source, string $expectedSha256): array
    {
        if (!in_array($source, ['ldr', 'plaw'], true) || !preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256)) {
            throw new RuntimeException('Invalid legacy workbook identity.');
        }
        if (is_link($path) || !is_file($path) || ($size = filesize($path)) === false || $size < 4 || $size > 32 * 1024 * 1024) {
            throw new RuntimeException('Legacy workbook is unavailable or exceeds the size limit.');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false || !hash_equals($expectedSha256, $hash)) {
            throw new RuntimeException('Legacy workbook does not match its recorded hash.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Legacy workbook is not a readable XLSX.');
        try {
            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['size'])) throw new RuntimeException('Invalid XLSX archive entry.');
                $uncompressed += (int) $stat['size'];
                if ($uncompressed > 128 * 1024 * 1024) throw new RuntimeException('Legacy workbook expands beyond the size limit.');
            }
        } finally { $zip->close(); }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Commission Summary']);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName('Commission Summary');
            if (!$sheet || $sheet->getHighestDataRow() > 5000) throw new RuntimeException('Legacy summary is missing or too large.');
            $headers = ['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location', 'Company'];
            foreach ($headers as $index => $header) {
                if (trim((string) $sheet->getCell(chr(65 + $index) . '1')->getValue()) !== $header) throw new RuntimeException('Legacy summary header is unexpected.');
            }
            $entries = []; $seen = []; $assignment = 'assigned'; $markerSeen = false;
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $nameCell = $sheet->getCell('A' . $row);
                if ($nameCell->getDataType() === DataType::TYPE_FORMULA) throw new RuntimeException('Legacy summary name contains a formula.');
                $name = trim((string) ($nameCell->getValue() ?? ''));
                if ($name === '') {
                    for ($col = 'B'; $col <= 'H'; $col++) if ($sheet->getCell($col . $row)->getValue() !== null && $sheet->getCell($col . $row)->getValue() !== '') throw new RuntimeException('Legacy summary has a row without an agent.');
                    continue;
                }
                if (strcasecmp($name, 'Unassigned Agents') === 0) {
                    if ($markerSeen) throw new RuntimeException('Duplicate unassigned section.');
                    $markerSeen = true; $assignment = 'unassigned'; continue;
                }
                $key = mb_strtolower(preg_replace('/\s+/u', ' ', $name), 'UTF-8');
                if (isset($seen[$key])) throw new RuntimeException('Duplicate agent in legacy summary.');
                $seen[$key] = true;
                $cell = $sheet->getCell('F' . $row); $value = $cell->getValue();
                if ($cell->getDataType() === DataType::TYPE_FORMULA || (!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 10000000) throw new RuntimeException('Invalid saved legacy commission.');
                $cents = (int) round($value * 100);
                if (abs($value * 100 - $cents) > 0.000001) throw new RuntimeException('Saved legacy commission is not in whole cents.');
                $entries[] = ['agent' => $name, 'commission_cents' => $cents, 'assignment' => $assignment, 'summary_row' => $row];
            }
            if (!$entries) throw new RuntimeException('Legacy summary has no agent rows.');
        } finally { $book->disconnectWorksheets(); }
        if (!hash_equals($expectedSha256, (string) hash_file('sha256', $path))) throw new RuntimeException('Legacy workbook changed while reading.');
        return ['source' => $source, 'sha256' => $hash, 'entries' => $entries, 'historical_line_pricing' => 'unverified', 'execution_receipt' => 'unverified'];
    }
}
