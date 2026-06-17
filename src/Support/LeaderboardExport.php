<?php

namespace Cmd\Reports\Support;

use Carbon\Carbon;
use Cmd\Reports\Repositories\LeaderboardReportRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;


class LeaderboardExport
{
    private const BAR_TITLE = '1F2A36';   // section title bar
    private const BAR_HEAD = '34495E';    // column header row
    private const COMPANY = 'EEF2FF';     // company-wide row tint
    private const GRID = 'D9DDE3';        // cell border

    public static function spreadsheet(LeaderboardReportRepository $repo, string $category, string $period): Spreadsheet
    {
        $layout = $repo->layout($category);
        $window = $repo->resolveWindow($period);
        $settings = $repo->settings($category, $period);
        $currentLeaders = $repo->currentLeaders($category, $period);
        $currentCompany = $repo->currentCompany($category, $period);
        $recordHolders = $repo->recordHolders($category, $period);
        $companyRecord = $repo->companyRecord($category, $period);
        $totalRecords = $repo->totalRecords();
        $header = $repo->currentHeader($category, $period, $window);
        $title = $repo->titleLabel($category, $period);

        $recordAmounts = $recordHolders->pluck('amount')->filter(fn($v) => $v !== null)->values();
        $ratioAsc = in_array($category, LeaderboardReportRepository::RATIO_ASC, true);
        $potRank = function ($amount) use ($recordAmounts, $ratioAsc) {
            if ($amount === null || $amount === '') {
                return '';
            }
            $better = 0;
            foreach ($recordAmounts as $r) {
                if ($ratioAsc ? ((float) $r < (float) $amount) : ((float) $r > (float) $amount)) {
                    $better++;
                }
            }
            $pos = $better + 1;
            return $pos <= 4 ? $pos : '';
        };

        $amtFmt = match ($layout['amount_format']) {
            'currency' => '"$"#,##0',
            'percent' => '0.00%',
            default => '#,##0',
        };
        $num = fn($v) => ($v === null || $v === '') ? '' : (float) $v;

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Leaderboard');

        $optCols = [];
        if ($layout['show_contacts']) {
            $optCols[] = ['label' => 'Contacts', 'fmt' => '#,##0', 'align' => 'right', 'key' => 'contacts'];
        }
        if ($layout['show_deals']) {
            $optCols[] = ['label' => $layout['deals_label'], 'fmt' => '#,##0', 'align' => 'right', 'key' => 'deals'];
        }
        if ($layout['show_debt']) {
            $optCols[] = ['label' => 'Debt', 'fmt' => '"$"#,##0', 'align' => 'right', 'key' => 'debt'];
        }

        $currentCols = array_merge(
            [
                ['label' => 'Current Rank', 'align' => 'center'],
                ['label' => 'Agent'],
                ['label' => $layout['amount_label'], 'fmt' => $amtFmt, 'align' => 'right'],
            ],
            $optCols,
            [['label' => 'Potential Rank', 'align' => 'center']]
        );
        $recordCols = array_merge(
            [
                ['label' => 'Leaderboard Rank', 'align' => 'center'],
                ['label' => 'Agent'],
                ['label' => $layout['amount_label'], 'fmt' => $amtFmt, 'align' => 'right'],
            ],
            $optCols,
            [['label' => 'Leaderboard Date']]
        );

        $optVals = function ($row) use ($optCols, $num) {
            $v = [];
            foreach ($optCols as $col) {
                $v[] = $num($row->{$col['key']} ?? null);
            }
            return $v;
        };

        $curData = [];
        $place = 0;
        foreach ($currentLeaders as $row) {
            $place++;
            $curData[] = array_merge(
                [$place, $row->agent ?? '', $num($row->amount ?? null)],
                $optVals($row),
                [$potRank($row->amount ?? null)]
            );
        }
        $curCompany = $currentCompany
            ? array_merge(['Company-Wide', '', $num($currentCompany->amount ?? null)], $optVals($currentCompany), [''])
            : null;

        $recData = [];
        $place = 0;
        foreach ($recordHolders as $row) {
            $place++;
            $recData[] = array_merge(
                [$place, $row->agent ?? '', $num($row->amount ?? null)],
                $optVals($row),
                [$row->record_date ? Carbon::parse($row->record_date)->format('n/j/Y') : '']
            );
        }
        $recCompany = $companyRecord
            ? array_merge(['Company-Wide', '', $num($companyRecord->amount ?? null)], $optVals($companyRecord), [$companyRecord->record_date ? Carbon::parse($companyRecord->record_date)->format('n/j/Y') : ''])
            : null;

        $maxCols = max(count($currentCols), count($recordCols), 2);
        $lastTitleCol = Coordinate::stringFromColumnIndex($maxCols);

        $r = 1;
        $sheet->setCellValue("A{$r}", $title);
        $sheet->mergeCells("A{$r}:{$lastTitleCol}{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(15);
        $r += 2;

        self::writeTable($sheet, $r, $header, $currentCols, $curData, $curCompany);
        self::writeTable($sheet, $r, 'Record Holders', $recordCols, $recData, $recCompany);
        self::writeRules($sheet, $r, $settings);
        self::writeTotals($sheet, $r, $totalRecords);

        for ($c = 1; $c <= $maxCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        return $ss;
    }

    /**
     * @param  array<int,array{label:string,fmt?:string,align?:string}>  $cols
     * @param  array<int,array<int,mixed>>  $rows
     * @param  array<int,mixed>|null  $companyRow
     */
    private static function writeTable(Worksheet $sheet, int &$r, string $title, array $cols, array $rows, ?array $companyRow): void
    {
        $n = count($cols);
        $lastCol = Coordinate::stringFromColumnIndex($n);

        $sheet->setCellValue("A{$r}", $title);
        $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
        self::bar($sheet, "A{$r}:{$lastCol}{$r}", self::BAR_TITLE, 12);
        $r++;

        foreach ($cols as $i => $col) {
            $L = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$L}{$r}", $col['label']);
        }
        self::bar($sheet, "A{$r}:{$lastCol}{$r}", self::BAR_HEAD, 11);
        $r++;

        $writeRow = function (array $values, bool $company = false) use (&$r, $sheet, $cols, $lastCol) {
            foreach ($cols as $i => $col) {
                $L = Coordinate::stringFromColumnIndex($i + 1);
                $val = $values[$i] ?? '';
                $sheet->setCellValue("{$L}{$r}", $val);
                $style = $sheet->getStyle("{$L}{$r}");
                if (! empty($col['fmt']) && $val !== '' && $val !== null) {
                    $style->getNumberFormat()->setFormatCode($col['fmt']);
                }
                $style->getAlignment()->setHorizontal($col['align'] ?? 'left');
            }
            $range = "A{$r}:{$lastCol}{$r}";
            self::border($sheet, $range);
            if ($company) {
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COMPANY);
                $sheet->getStyle($range)->getFont()->setBold(true);
            }
            $r++;
        };

        foreach ($rows as $row) {
            $writeRow($row);
        }
        if ($companyRow !== null) {
            $writeRow($companyRow, true);
        }

        $r++;
    }

    private static function writeRules(Worksheet $sheet, int &$r, ?object $settings): void
    {
        $sheet->setCellValue("A{$r}", 'Leaderboard Rules');
        $sheet->mergeCells("A{$r}:B{$r}");
        self::bar($sheet, "A{$r}:B{$r}", self::BAR_TITLE, 12);
        $r++;

        $rows = [['Required Contacts', $settings ? (int) ($settings->Threshold ?? 0) : 0]];
        if (! empty($settings->Activity_Cutoff)) {
            $rows[] = ['Client Activity Cutoff', $settings->Activity_Cutoff];
        }
        if (! empty($settings->Tiebreaker)) {
            $rows[] = ['Tiebreaker', $settings->Tiebreaker];
        }
        if (! empty($settings->Notes)) {
            $rows[] = ['Notes', $settings->Notes];
        }
        $bonusParts = [];
        if ($settings) {
            foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'] as $n => $lbl) {
                $b = (float) ($settings->{"Bonus_$n"} ?? 0);
                if ($b > 0) {
                    $bonusParts[] = $lbl . ' - $' . number_format($b, 0);
                }
            }
        }
        if ($bonusParts) {
            $rows[] = ['Record Breaking Bonus', implode(', ', $bonusParts)];
        }

        foreach ($rows as $row) {
            $sheet->setCellValue("A{$r}", $row[0]);
            $sheet->setCellValue("B{$r}", $row[1]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F2F5');
            self::border($sheet, "A{$r}:B{$r}");
            $r++;
        }
        $r++;
    }

    /**
     * @param  iterable<object>  $totalRecords
     */
    private static function writeTotals(Worksheet $sheet, int &$r, $totalRecords): void
    {
        $sheet->setCellValue("A{$r}", 'Total Records');
        $sheet->mergeCells("A{$r}:B{$r}");
        self::bar($sheet, "A{$r}:B{$r}", self::BAR_TITLE, 12);
        $r++;

        $sheet->setCellValue("A{$r}", 'Agent');
        $sheet->setCellValue("B{$r}", 'Total Records');
        self::bar($sheet, "A{$r}:B{$r}", self::BAR_HEAD, 11);
        $r++;

        foreach ($totalRecords as $row) {
            $parts = [];
            foreach (['1st' => $row->first_count ?? 0, '2nd' => $row->second_count ?? 0, '3rd' => $row->third_count ?? 0, '4th' => $row->fourth_count ?? 0] as $lbl => $c) {
                if ((int) $c > 0) {
                    $parts[] = (int) $c . 'x ' . $lbl;
                }
            }
            $bd = implode(', ', $parts);
            $sheet->setCellValue("A{$r}", $row->agent ?? '');
            $sheet->setCellValue("B{$r}", $row->records . ($bd !== '' ? ': ' . $bd : ''));
            self::border($sheet, "A{$r}:B{$r}");
            $r++;
        }
    }

    private static function bar(Worksheet $sheet, string $range, string $rgb, int $size): void
    {
        $style = $sheet->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
        $style->getFont()->setBold(true)->setSize($size)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setVertical('center');
        self::border($sheet, $range);
    }

    private static function border(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GRID);
    }
}
