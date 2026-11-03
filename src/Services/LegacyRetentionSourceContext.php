<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Partial legacy evidence only. It is deliberately incompatible with complete commission components. */
final class LegacyRetentionSourceContext
{
    private const HASHES = [
        'ldr' => 'dfb2444ba23152c1c0d00112dcc2bc93e990da4f338c326686d41b4b1374eaf4',
        'plaw' => 'c7c48bd51b9fab0d7cd7a29840d308b22e291d1948ed89cc6a73a49de16734b5',
    ];

    public static function build(string $path, string $source, string $period): array
    {
        if ($period !== '2026-10-01' || !isset(self::HASHES[$source])) throw new \InvalidArgumentException('Legacy Retention scope is invalid.');
        $hash = self::HASHES[$source];
        $summary = LegacyRetentionWorkbook::read($path, $source, $hash);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Retention Data']);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName('Retention Data');
            if (!$sheet) throw new \RuntimeException('The original Retention detail sheet is unavailable.');
            $detail = self::detailOnlyAgents($sheet, $summary['entries'], $period);
        } finally { $book->disconnectWorksheets(); }
        if (is_link($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) throw new \RuntimeException('Legacy workbook changed during inspection.');
        return ['legacyEvidence' => [
            'version' => 1, 'source' => $source, 'period' => $period, 'workbookSha256' => $hash,
            'periodBasis' => 'inferred_from_original_generation_date_and_report_default',
            'summaryEntries' => $summary['entries'], 'detailOnlyAgents' => $detail,
            'coverage' => 'unverified', 'historicalLinePricing' => 'unverified',
            'executionReceipt' => 'unverified', 'eligibleForPayment' => false,
        ]];
    }

    /** Exposes unknown detail-only identities, without assigning earnings or treating absence as zero. */
    public static function detailOnlyAgents(Worksheet $sheet, array $summaryEntries, string $period): array
    {
        if ($period !== '2026-10-01' || $sheet->getHighestDataRow() > 100000) throw new \RuntimeException('Invalid legacy Retention detail scope.');
        foreach (['C1' => 'Retention Agent', 'D1' => 'Retention Date', 'J1' => 'Retention Payment Date'] as $cell => $expected) {
            if (trim((string) $sheet->getCell($cell)->getValue()) !== $expected) throw new \RuntimeException('Legacy Retention detail headers are unexpected.');
        }
        $known = [];
        foreach ($summaryEntries as $entry) {
            $name = $entry['agent'] ?? null;
            if (!is_string($name) || self::key($name) === '') throw new \RuntimeException('Invalid legacy summary agent.');
            $known[self::key($name)] = true;
        }
        $missing = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            foreach (['C', 'D', 'J'] as $column) if ($sheet->getCell($column . $row)->getDataType() === DataType::TYPE_FORMULA) throw new \RuntimeException('Legacy detail contains an unverifiable formula.');
            $name = trim((string) ($sheet->getCell('C' . $row)->getValue() ?? ''));
            $retained = self::date($sheet->getCell('D' . $row)->getValue());
            $payment = self::date($sheet->getCell('J' . $row)->getValue());
            $key = self::key($name);
            if ($key === '') {
                if ($retained !== null || $payment !== null) throw new \RuntimeException('A legacy Retention detail row has no agent.');
                continue;
            }
            if (isset($known[$key])) continue;
            $missing[$key] ??= ['agent' => $name, 'detailRows' => 0, 'potentialPayableRows' => 0, 'amount' => null];
            $missing[$key]['detailRows']++;
            if ($retained !== null && $payment !== null && $payment >= '2026-10-01' && $payment < '2026-11-01') $missing[$key]['potentialPayableRows']++;
        }
        ksort($missing, SORT_STRING);
        return array_values($missing);
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '', 'UTF-8');
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || $value < 1 || $value > 2958465) throw new \RuntimeException('Invalid legacy date.');
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/\A(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?\z/', $value, $match)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
            if ($date && $date->format('Y-m-d') === $match[1]) return $match[1];
        }
        throw new \RuntimeException('Invalid legacy Retention date.');
    }
}
