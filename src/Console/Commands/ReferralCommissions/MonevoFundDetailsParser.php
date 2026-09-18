<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\ReferralCommissions;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads Monevo's "Monevo US Partner Fund Details.xlsx" the way ProcessReferralCommissions in
 * CMD LDR.xlsm does: by column POSITION, after the optional "Processed Date" column B is removed
 * (`If Range("B1").Value = "Processed Date" Then Range("B:B").EntireColumn.Delete`).
 *
 * The VBA never looks at a header name, so neither does this — the letters below are the ones it
 * reads once column B is gone. The header row is still captured and reported so the first real
 * file tells us what those positions are called.
 *
 * A row is a funding when column P (the numeric part of the LLG id / CCS CID) is non-empty; the
 * VBA works down to the last non-empty A and drops anything whose contact is not found, which
 * covers a blank P the same way.
 */
final class MonevoFundDetailsParser
{
    public const PROCESSED_DATE_HEADER = 'Processed Date';

    /** 0-based indexes after the optional column B removal. */
    private const COL_FUNDING_DATE = 0;   // A — Funding_Date and the payroll month
    private const COL_COMMISSION = 4;     // E — Monevo's fee, stored as TblFundings.Commission
    private const COL_LOAN_AMOUNT = 10;   // K
    private const COL_RATE = 11;          // L — written to BOTH APR and Interest_Rate
    private const COL_TERM = 12;          // M — months
    private const COL_LENDER = 14;        // O
    private const COL_CLIENT_ID = 15;     // P — numeric id: LLG-<P> on LT, CID = P on CCS

    public const COLUMN_LABELS = [
        self::COL_FUNDING_DATE => 'A funding date',
        self::COL_COMMISSION => 'E commission',
        self::COL_LOAN_AMOUNT => 'K loan amount',
        self::COL_RATE => 'L rate',
        self::COL_TERM => 'M term',
        self::COL_LENDER => 'O lender',
        self::COL_CLIENT_ID => 'P client id',
    ];

    /**
     * @return array{
     *     rows: list<array{
     *         row: int, client_id: string, funding_date: ?string, commission: float,
     *         loan_amount: ?float, loan_amount_text: string, rate: ?float, rate_text: string,
     *         term: ?int, term_text: string, lender: string
     *     }>,
     *     headers: array<int, string>,
     *     processed_date_removed: bool,
     *     skipped: list<string>
     * }
     */
    public static function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);   // keep number formats: dates are serials with a date format
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $grid = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $cell = $sheet->getCell([$c, $r]);
                $row[] = self::cellValue($cell);
            }
            $grid[] = $row;
        }
        $spreadsheet->disconnectWorksheets();

        $processedDateRemoved = false;
        if (isset($grid[0][1]) && trim(self::scalar($grid[0][1])) === self::PROCESSED_DATE_HEADER) {
            foreach ($grid as &$row) {
                array_splice($row, 1, 1);
            }
            unset($row);
            $processedDateRemoved = true;
        }

        $headers = [];
        foreach (self::COLUMN_LABELS as $index => $label) {
            $headers[$index] = trim(self::scalar($grid[0][$index] ?? null));
        }

        $rows = [];
        $skipped = [];
        for ($i = 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $sheetRow = $i + 1;

            $clientId = self::clientId($row[self::COL_CLIENT_ID] ?? null);
            if ($clientId === '') {
                if (self::scalar($row[self::COL_FUNDING_DATE] ?? null) !== '') {
                    $skipped[] = "row {$sheetRow}: no client id in column P";
                }
                continue;
            }

            $term = self::number($row[self::COL_TERM] ?? null);

            $rows[] = [
                'row' => $sheetRow,
                'client_id' => $clientId,
                'funding_date' => self::date($row[self::COL_FUNDING_DATE] ?? null),
                'commission' => self::number($row[self::COL_COMMISSION] ?? null) ?? 0.0,   // VBA Val()
                'loan_amount' => self::number($row[self::COL_LOAN_AMOUNT] ?? null),
                'loan_amount_text' => self::text($row[self::COL_LOAN_AMOUNT] ?? null),
                'rate' => self::number($row[self::COL_RATE] ?? null),
                'rate_text' => self::text($row[self::COL_RATE] ?? null),
                'term' => $term === null ? null : (int) $term,
                'term_text' => self::text($row[self::COL_TERM] ?? null),
                'lender' => self::text($row[self::COL_LENDER] ?? null),
            ];
        }

        return [
            'rows' => $rows,
            'headers' => $headers,
            'processed_date_removed' => $processedDateRemoved,
            'skipped' => $skipped,
        ];
    }

    /**
     * A date-formatted numeric cell becomes a DateTimeImmutable so it is not lost to the cell's
     * display format; everything else is the raw value (formula cells: their computed value).
     */
    private static function cellValue(Cell $cell): mixed
    {
        $raw = $cell->getCalculatedValue();
        if (is_numeric($raw) && ExcelDate::isDateTime($cell)) {
            return \DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $raw));
        }

        return $raw;
    }

    private static function scalar(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null ? '' : (string) $value;
    }

    /** The VBA concatenates the cell into text (note body, CRM fields); reproduce what `.Value & ""` gives. */
    private static function text(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value)) {
            // 35.8 -> "35.8", 4200.0 -> "4200"
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return trim((string) ($value ?? ''));
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value instanceof \DateTimeInterface) {
            return null;
        }
        $clean = str_replace(['$', ',', '%', ' '], '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return ExcelDate::excelToDateTimeObject((float) $text)->format('Y-m-d');
        }
        $timestamp = strtotime($text);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /** Column P as Excel holds it — usually a number, which must not come out as 1.2469E+9. */
    private static function clientId(mixed $value): string
    {
        if ($value === null || $value instanceof \DateTimeInterface) {
            return '';
        }
        if (is_float($value)) {
            return sprintf('%.0f', $value);
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return trim((string) $value);
    }
}
