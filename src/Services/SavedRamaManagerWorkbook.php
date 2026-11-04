<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/** Replay an identified saved manager file against the exact original account facts. No source writes. */
final class SavedRamaManagerWorkbook
{
    private const HEADERS = ['ID', 'CLIENT', 'RETENTION AGENT', 'RETENTION DATE', 'IMMEDIATE RESULTS', 'ENROLLED DEBT',
        'CLEARED PAYMENTS', 'RECONSIDERATION', 'DROPPED DATE', 'PAYMENT DATE', 'TIER 1 RATE', 'TIER 2 RATE', 'TIER 3 RATE', 'TIER 4 RATE',
        'CANCEL REQUEST DATE', 'TRANCHE', 'CUT OFF', 'MADE CUT OFF', 'BONUS'];

    public static function read(string $path, string $expectedSha256, string $period, array $artifacts, ?\Closure $calculate = null): array
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m-d', $period);
        if (!$month || $month->format('Y-m-01') !== $period) throw new RuntimeException('Saved manager reporting month is invalid.');
        $end = $month->format('Y-m-t');
        self::verifyFile($path, $expectedSha256);
        $book = self::load($path);
        try {
            $created = $book->getProperties()->getCreated();
            if (!is_int($created) || $created < 1) throw new RuntimeException('Saved manager capture date is unavailable.');
            $capturedAt = gmdate('Y-m-d\TH:i:s\Z', $created);
            $sheet = $book->getSheetByName('Retention Data');
            self::headers($sheet, true);
            $saved = $context = [];
            for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
                $row = self::facts($sheet, $r);
                if ($row[10] === null || $row[10] < $period || $row[10] > $end || isset($saved[$row[0]])) {
                    throw new RuntimeException('Saved manager client identity or reporting period differs.');
                }
                $saved[$row[0]] = $row;
                $tranche = self::value($sheet, 16, $r);
                if ($tranche === null || $tranche === '') $tranche = '';
                elseif ((is_int($tranche) || is_float($tranche)) && is_finite((float) $tranche) && $tranche >= 0 && $tranche <= 1000000 && floor((float) $tranche) === (float) $tranche) $tranche = (string) (int) $tranche;
                elseif (!is_string($tranche) || !preg_match('/^\d{1,7}$/D', $tranche)) throw new RuntimeException('Saved manager tranche is invalid.');
                $made = self::value($sheet, 18, $r);
                if (!in_array($made, [null, '', 'x'], true)) throw new RuntimeException('Saved manager cutoff marker is invalid.');
                $bonus = self::value($sheet, 19, $r) ?? '';
                if (!in_array($bonus, ['', 'Bonus', 'No Bonus'], true)) throw new RuntimeException('Saved manager bonus marker is invalid.');
                $cutoff = self::date(self::value($sheet, 17, $r));
                if (($made === 'x') !== ($bonus !== '') || ($bonus !== '' && ($tranche === '' || $cutoff === null))) {
                    throw new RuntimeException('Saved manager bonus context is inconsistent.');
                }
                $context['LLG-' . $row[0]] = ['TRANCHE' => $tranche, 'CUT_OFF' => $cutoff, 'MADE_CUT_OFF' => $made === 'x', 'BONUS_FLAG' => $bonus];
            }
            if (!$saved) throw new RuntimeException('Saved manager detail is empty.');
            $summary = self::summary($book->getSheetByName('Commission Summary'));
        } finally { $book->disconnectWorksheets(); }

        $original = []; $enriched = [];
        foreach (['ldr', 'plaw'] as $source) {
            $key = 'retention_' . $source; $artifact = $artifacts[$key] ?? null;
            if (!is_array($artifact) || ($artifact['key'] ?? null) !== $key || ($artifact['period'] ?? null) !== $period
                || !is_string($artifact['path'] ?? null)) throw new RuntimeException('Both exact original Retention artifacts are required.');
            self::verifyFile($artifact['path'], $artifact['sha256'] ?? '');
            $sourceBook = self::load($artifact['path']); $sourceContext = [];
            try {
                $sourceSheet = $sourceBook->getSheetByName('Retention Data') ?? $sourceBook->getSheetByName('Retention Commission Report');
                self::headers($sourceSheet, false);
                for ($r = 2; $r <= $sourceSheet->getHighestDataRow(); $r++) {
                    $cancel = self::date(self::value($sourceSheet, 15, $r));
                    if ($cancel === null || $cancel < $period || $cancel > $end) continue;
                    $row = self::facts($sourceSheet, $r); $id = $row[0];
                    if (isset($original[$id]) || !isset($saved[$id]) || $row !== $saved[$id]) throw new RuntimeException('Saved manager detail does not match the exact original Retention facts.');
                    $original[$id] = true; $sourceContext['LLG-' . $id] = $context['LLG-' . $id];
                }
            } finally { $sourceBook->disconnectWorksheets(); }
            $enriched[$key] = [...$artifact, 'context' => [...($artifact['context'] ?? []), 'trancheContextReady' => true, 'trancheByClient' => $sourceContext]];
        }
        if (count($original) !== count($saved)) throw new RuntimeException('Saved manager account coverage differs from the originals.');
        $calculate ??= static fn (...$args) => (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipSnapshot(...$args);
        $snapshot = $calculate('retention_manager', $period, $end, $enriched);
        if (($snapshot['reportKind'] ?? null) !== 'retention_manager' || ($snapshot['managerName'] ?? null) !== 'Rama Davis'
            || ($snapshot['periodStart'] ?? null) !== $period || ($snapshot['periodEnd'] ?? null) !== $end || !is_array($snapshot['lines'] ?? null)) {
            throw new RuntimeException('Replayed manager snapshot is invalid.');
        }
        foreach ($summary as $field => $expected) {
            $actual = $snapshot['summary'][$field] ?? null;
            if ((!is_int($actual) && !is_float($actual)) || !is_finite((float) $actual) || abs((float) $actual - $expected) > 0.00000001) {
                throw new RuntimeException('Replayed manager commission does not match the saved summary.');
            }
        }
        self::verifyFile($path, $expectedSha256);
        foreach ($enriched as $artifact) self::verifyFile($artifact['path'], $artifact['sha256']);
        return ['snapshot' => $snapshot, 'managerWorkbookEvidence' => ['kind' => 'saved_rama_manager_workbook_v1',
            'sha256' => $expectedSha256, 'byteLength' => filesize($path), 'capturedAt' => $capturedAt,
            'basis' => 'saved_manager_tranche_context_replayed_v1', 'clientCount' => count($saved),
            'reviewOnly' => true, 'paymentEligible' => false]];
    }

    private static function verifyFile(string $path, string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || is_link($path) || !is_file($path) || !is_readable($path)
            || ($size = filesize($path)) < 4 || $size > 32 * 1024 * 1024 || !hash_equals($hash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Saved manager or original workbook identity is invalid.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new RuntimeException('Saved manager workbook is not a readable XLSX.');
        try {
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $stat = $zip->statIndex($i); $expanded += $stat['size'] ?? 134217729;
                if ($expanded > 128 * 1024 * 1024) throw new RuntimeException('Saved manager workbook exceeds the expanded size limit.'); }
        } finally { $zip->close(); }
    }

    private static function load(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
    { $reader = IOFactory::createReader('Xlsx'); $reader->setReadDataOnly(true); return $reader->load($path); }

    private static function headers(?Worksheet $sheet, bool $manager): void
    {
        if (!$sheet || $sheet->getHighestDataRow() > 20000 || $sheet->getHighestDataRow() < 2) throw new RuntimeException('Saved manager detail sheet is invalid.');
        foreach (array_slice(self::HEADERS, 0, $manager ? 19 : 15) as $index => $expected) {
            $actual = strtoupper(trim((string) self::value($sheet, $index + 1, 1)));
            $actual = str_replace(['RECONSIDERATION DATE', 'RETENTION PAYMENT DATE', 'T1 RATE', 'T2 RATE', 'T3 RATE', 'T4 RATE'],
                ['RECONSIDERATION', 'PAYMENT DATE', 'TIER 1 RATE', 'TIER 2 RATE', 'TIER 3 RATE', 'TIER 4 RATE'], $actual);
            if ($actual !== $expected) throw new RuntimeException('Saved manager detail headers are invalid.');
        }
    }

    /** First ten source fields plus cancellation date; tier display rates do not price Rama. */
    private static function facts(Worksheet $sheet, int $row): array
    {
        $out = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15] as $column) {
            $value = self::value($sheet, $column, $row);
            if (in_array($column, [4, 8, 9, 10, 15], true)) $value = self::date($value);
            elseif ($column === 1) { $value = (string) $value; if (!preg_match('/^[1-9]\d{0,15}$/D', $value)) throw new RuntimeException('Saved manager client identity is invalid.'); }
            elseif (in_array($column, [6, 7], true)) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1e12 || ($column === 7 && floor((float) $value) !== (float) $value)) throw new RuntimeException('Saved manager source number is invalid.');
                $value = (float) $value;
            } else { if (!is_string($value) || trim($value) === '') throw new RuntimeException('Saved manager source text is invalid.'); $value = trim($value); }
            $out[] = $value;
        }
        return $out;
    }

    private static function value(Worksheet $sheet, int $column, int $row): mixed
    {
        $cell = $sheet->getCell([$column, $row]);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) throw new RuntimeException('Saved manager inputs cannot contain formulas.');
        return $cell->getValue();
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 1 && $value <= 2958465) return XlDate::excelToDateTimeObject($value)->format('Y-m-d');
        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?$/D', $value, $parts)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $parts[1]); if ($date && $date->format('Y-m-d') === $parts[1]) return $parts[1];
        }
        throw new RuntimeException('Saved manager date is invalid.');
    }

    private static function summary(?Worksheet $sheet): array
    {
        if (!$sheet || $sheet->getHighestDataRow() !== 15) throw new RuntimeException('Saved manager summary is invalid.');
        $fields = [4 => ['Reconsideration Pending', 'assignedCount'], 5 => ['Retained', 'retainedCount'], 6 => ['Rate', 'retentionRate'],
            7 => ['Commission 1', 'commission'], 10 => ['Bonus Reconsideration', 'bonusAssignedCount'], 11 => ['Bonus Retained', 'bonusRetainedCount'],
            12 => ['Rate', 'bonusRate'], 13 => ['Commission 2', 'bonusCommission'], 15 => ['Commission Total', 'totalCommission']];
        $out = [];
        foreach ($fields as $row => [$label, $field]) {
            $value = self::value($sheet, 2, $row);
            if (self::value($sheet, 1, $row) !== $label || (!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) throw new RuntimeException('Saved manager summary value is invalid.');
            $out[$field] = (float) $value;
        }
        $out['totalPay'] = $out['totalCommission'];
        if (abs($out['commission'] + $out['bonusCommission'] - $out['totalPay']) > 0.00000001) throw new RuntimeException('Saved manager summary does not reconcile.');
        return $out;
    }
}
