<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\Style\Language;
use Symfony\Component\Process\Process;

class ReportBuilder
{
    private const TABLE_BORDER_SIZE = 6;
    private const TABLE_BORDER_COLOR = '999999';
    private const HEADER_FILL = '17853B';
    private const HEADER_TEXT_COLOR = 'FFFFFF';
    private const ALT_ROW_FILL = 'F5F7FA';

    private const COL_AGENT_WIDTH = 3000;
    private const COL_VAR_WIDTH = 1500;
    private const COL_VAL_WIDTH = 1800;

    public function build(
        array $agentMetrics,
        string $dataSource,
        string $startDate,
        string $endDate,
        bool $continuation
    ): array {
        $filename = $this->buildFilename($dataSource, $startDate, $continuation);
        $docxFilename = preg_replace('/\.pdf$/', '.docx', $filename);
        $docxPath = storage_path('app/' . $docxFilename);
        $pdfPath = storage_path('app/' . $filename);

        $phpWord = $this->buildDocument($agentMetrics, $dataSource, $startDate, $endDate, $continuation);

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($docxPath);

        $this->convertToPdf($docxPath, dirname($pdfPath));

        if (is_file($docxPath)) {
            @unlink($docxPath);
        }

        if (!is_file($pdfPath)) {
            throw new \RuntimeException("PDF conversion failed; expected file not found at {$pdfPath}");
        }

        return [
            'filename' => $filename,
            'path' => $pdfPath,
        ];
    }

    private function buildDocument(
        array $rows,
        string $dataSource,
        string $startDate,
        string $endDate,
        bool $continuation
    ): PhpWord {
        $phpWord = new PhpWord();
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::EN_US));

        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(9);

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginLeft' => 720,
            'marginRight' => 720,
            'marginTop' => 720,
            'marginBottom' => 720,
        ]);

        $titleText = $this->buildTitle($dataSource, $endDate, $continuation, $startDate);
        $section->addText(
            $titleText,
            ['bold' => true, 'size' => 14],
            ['alignment' => 'center', 'spaceAfter' => 200]
        );

        $section->addText(
            'Period: ' . date('m/d/Y', strtotime($startDate)) . ' – ' . date('m/d/Y', strtotime($endDate)),
            ['size' => 9, 'italic' => true],
            ['alignment' => 'center', 'spaceAfter' => 300]
        );

        // Table 1: Contacts | Deals | Conversion | Debt
        $this->addSectionHeading($section, 'Production');
        $this->addMetricSubTable($section, 'Contacts',   $rows, 'contacts',         'contacts',         'desc', 'int',     false,           $continuation);
        $this->addMetricSubTable($section, 'Deals',      $rows, 'deals_current',    'deals_eom',        'desc', 'int',     $continuation,   $continuation);
        $this->addMetricSubTable($section, 'Conversion', $rows, 'conversion_current','conversion_eom',  'desc', 'percent', true,            $continuation);
        $this->addMetricSubTable($section, 'Debt',       $rows, 'debt_current',     'debt_eom',         'desc', 'money_k', true,            $continuation);

        $section->addPageBreak();

        // Table 2: Cancels | NSFs | Cancels % | NSFs %
        $this->addSectionHeading($section, 'Risk');
        $this->addMetricSubTable($section, 'Cancels',     $rows, 'cancels_current',     'cancels_eom',     'asc',  'int',     true, $continuation);
        $this->addMetricSubTable($section, 'NSFs',        $rows, 'nsfs_current',        'nsfs_eom',        'asc',  'int',     true, $continuation);
        $this->addMetricSubTable($section, 'Cancels %',   $rows, 'cancels_pct_current', 'cancels_pct_eom', 'asc',  'percent', true, $continuation);
        $this->addMetricSubTable($section, 'NSFs %',      $rows, 'nsfs_pct_current',    'nsfs_pct_eom',    'asc',  'percent', true, $continuation);

        $section->addPageBreak();

        // Table 3: Average Debt | SMP
        $this->addSectionHeading($section, 'Quality');
        $this->addMetricSubTable($section, 'Average Debt', $rows, 'avg_debt_current', 'avg_debt_eom', 'desc', 'money_k', true, $continuation);
        $this->addMetricSubTable($section, 'SMP',          $rows, 'smp_current',      'smp_eom',      'desc', 'int',     true, $continuation);

        return $phpWord;
    }

    private function addSectionHeading($section, string $heading): void
    {
        $section->addText(
            $heading,
            ['bold' => true, 'size' => 12, 'color' => self::HEADER_FILL],
            ['spaceBefore' => 200, 'spaceAfter' => 100]
        );
    }

    private function addMetricSubTable(
        $section,
        string $metricLabel,
        array $rows,
        string $currentKey,
        string $eomKey,
        string $sortDir,
        string $valueFormat,
        bool $showVariance,
        bool $continuation
    ): void {
        $section->addText(
            $metricLabel,
            ['bold' => true, 'size' => 10],
            ['spaceBefore' => 150, 'spaceAfter' => 50]
        );

        $sorted = $this->sortRows($rows, $currentKey, $sortDir);

        $tableStyle = [
            'borderSize' => self::TABLE_BORDER_SIZE,
            'borderColor' => self::TABLE_BORDER_COLOR,
            'cellMargin' => 60,
            'alignment' => JcTable::CENTER,
        ];

        $table = $section->addTable($tableStyle);

        $headerCellStyle = ['bgColor' => self::HEADER_FILL];
        $headerFontStyle = ['bold' => true, 'color' => self::HEADER_TEXT_COLOR, 'size' => 9];

        $table->addRow();
        $table->addCell(self::COL_AGENT_WIDTH, $headerCellStyle)->addText('Agent', $headerFontStyle);
        if ($showVariance) {
            $table->addCell(self::COL_VAR_WIDTH, $headerCellStyle)->addText('Variance', $headerFontStyle, ['alignment' => 'center']);
        }
        $table->addCell(self::COL_VAL_WIDTH, $headerCellStyle)->addText($metricLabel, $headerFontStyle, ['alignment' => 'center']);

        $rowIndex = 0;
        $totalCurrent = 0;
        $totalCount = 0;
        foreach ($sorted as $row) {
            $rowIndex++;
            $isEven = $rowIndex % 2 === 0;
            $cellStyle = $isEven ? ['bgColor' => self::ALT_ROW_FILL] : [];

            $current = $row[$currentKey] ?? 0;
            $eom = $row[$eomKey] ?? 0;
            $variance = $this->computeVariance($current, $eom, $valueFormat);

            $table->addRow();
            $table->addCell(self::COL_AGENT_WIDTH, $cellStyle)->addText((string) ($row['agent'] ?? ''), ['size' => 9]);
            if ($showVariance) {
                $table->addCell(self::COL_VAR_WIDTH, $cellStyle)->addText(
                    $this->formatVariance($variance, $valueFormat),
                    ['size' => 9],
                    ['alignment' => 'right']
                );
            }
            $table->addCell(self::COL_VAL_WIDTH, $cellStyle)->addText(
                $this->formatValue($current, $valueFormat),
                ['size' => 9],
                ['alignment' => 'right']
            );

            $totalCurrent += is_numeric($current) ? (float) $current : 0;
            $totalCount++;
        }

        // Total + Average summary rows
        if ($totalCount > 0 && $this->shouldShowTotalAverage($valueFormat)) {
            $summaryCellStyle = ['bgColor' => 'E0E6EC'];
            $summaryFontStyle = ['bold' => true, 'size' => 9];

            if ($this->shouldShowTotal($valueFormat)) {
                $table->addRow();
                $table->addCell(self::COL_AGENT_WIDTH, $summaryCellStyle)->addText('Total', $summaryFontStyle);
                if ($showVariance) {
                    $table->addCell(self::COL_VAR_WIDTH, $summaryCellStyle)->addText('');
                }
                $table->addCell(self::COL_VAL_WIDTH, $summaryCellStyle)->addText(
                    $this->formatValue($totalCurrent, $valueFormat),
                    $summaryFontStyle,
                    ['alignment' => 'right']
                );
            }

            $table->addRow();
            $table->addCell(self::COL_AGENT_WIDTH, $summaryCellStyle)->addText('Average', $summaryFontStyle);
            if ($showVariance) {
                $table->addCell(self::COL_VAR_WIDTH, $summaryCellStyle)->addText('');
            }
            $table->addCell(self::COL_VAL_WIDTH, $summaryCellStyle)->addText(
                $this->formatValue($totalCurrent / $totalCount, $valueFormat),
                $summaryFontStyle,
                ['alignment' => 'right']
            );
        }
    }

    private function shouldShowTotal(string $format): bool
    {
        return in_array($format, ['int', 'money_k'], true);
    }

    private function shouldShowTotalAverage(string $format): bool
    {
        return true;
    }

    private function sortRows(array $rows, string $key, string $dir): array
    {
        $sorted = $rows;
        usort($sorted, function ($a, $b) use ($key, $dir) {
            $av = $a[$key] ?? 0;
            $bv = $b[$key] ?? 0;
            if ($av === $bv) {
                return strcmp((string) ($a['agent'] ?? ''), (string) ($b['agent'] ?? ''));
            }
            return $dir === 'desc' ? ($bv <=> $av) : ($av <=> $bv);
        });
        return $sorted;
    }

    private function computeVariance($current, $eom, string $format): float
    {
        $cur = is_numeric($current) ? (float) $current : 0;
        $em = is_numeric($eom) ? (float) $eom : 0;

        if ($format === 'money_k') {
            return round(($cur - $em) / 1000, 0);
        }
        if ($format === 'percent') {
            return round($cur - $em, 4);
        }
        return round($cur - $em, 0);
    }

    private function formatVariance(float $variance, string $format): string
    {
        if (abs($variance) < 1e-9) {
            return '';
        }

        $sign = $variance > 0 ? '+' : '-';
        $abs = abs($variance);

        return $sign . $this->formatAbsValue($abs, $format);
    }

    private function formatValue($value, string $format): string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }
        $num = is_numeric($value) ? (float) $value : 0;
        return $this->formatAbsValue($num, $format);
    }

    private function formatAbsValue(float $value, string $format): string
    {
        switch ($format) {
            case 'percent':
                return number_format($value * 100, 2) . '%';
            case 'money_k':
                return '$' . number_format($value / 1000) . 'k';
            case 'int':
            default:
                return number_format($value);
        }
    }

    private function buildTitle(string $dataSource, string $endDate, bool $continuation, string $startDate): string
    {
        $title = 'Agent Summary Report - ' . date('m/d/Y', strtotime($endDate));
        if ($continuation) {
            $title .= ' (' . date('F Y', strtotime($startDate)) . ')';
        }
        $title .= ' - ' . $dataSource;
        return $title;
    }

    private function buildFilename(string $dataSource, string $startDate, bool $continuation): string
    {
        $cont = $continuation ? 'Continuation ' : '';
        $contSuffix = $continuation ? ' (' . date('F Y', strtotime($startDate)) . ')' : '';
        $today = date('m-d-Y');
        return "Agent Summary {$cont}Report - {$today}{$contSuffix} - {$dataSource}.pdf";
    }

    private function convertToPdf(string $docxPath, string $outputDir): void
    {
        $binary = $this->resolveLibreOfficeBinary();

        $process = new Process([
            $binary,
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', $outputDir,
            $docxPath,
        ]);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $stdout = $process->getOutput();
            Log::error('LibreOffice PDF conversion failed.', [
                'docx' => $docxPath,
                'output_dir' => $outputDir,
                'binary' => $binary,
                'exit_code' => $process->getExitCode(),
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);
            throw new \RuntimeException("LibreOffice failed (exit {$process->getExitCode()}): {$stderr}");
        }
    }

    private function resolveLibreOfficeBinary(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates = [
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            ];
            foreach ($candidates as $c) {
                if (is_file($c)) {
                    return $c;
                }
            }
            return 'soffice';
        }

        foreach (['libreoffice', 'soffice'] as $bin) {
            $which = trim((string) @shell_exec("command -v {$bin} 2>/dev/null"));
            if ($which !== '') {
                return $bin;
            }
        }
        return 'libreoffice';
    }
}
