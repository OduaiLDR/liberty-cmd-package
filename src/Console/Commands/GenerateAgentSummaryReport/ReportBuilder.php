<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\SimpleType\JcTable;
use Symfony\Component\Process\Process;

/**
 * Builds the Agent Summary Report PDF.
 *
 * Loads the LDR-branded template (Agent Summary Report Template.docx) which provides
 * page setup, header, footer, and watermark. The body (which is empty in the template)
 * gets filled with the title and 3 wide multi-block tables matching the original VBA layout:
 *
 *   Table 1 (Production): Contacts | Deals | Conversion | Debt — 4 metric blocks side-by-side
 *   Table 2 (Risk):       Cancels | NSFs | Cancels % | NSFs % — 4 metric blocks
 *   Table 3 (Quality):    Avg Debt | SMP — 2 metric blocks
 *
 * Each block has its own sort order; row N in a block shows the Nth-ranked agent for that metric.
 */
class ReportBuilder
{
    private const TEMPLATE_PATH = __DIR__ . '/../../../../resources/templates/Agent Summary Report Template.docx';

    private const HEADER_FILL = '17853B';
    private const HEADER_TEXT_COLOR = 'FFFFFF';
    private const SUMMARY_FILL = 'E0E6EC';
    private const ALT_ROW_FILL = 'F5F7FA';
    private const BORDER_COLOR = '999999';

    private const W_AGENT = 1500;
    private const W_VAR = 600;
    private const W_VALUE = 1100;
    private const W_SEP = 300;

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
        $templatePath = realpath(self::TEMPLATE_PATH);
        if ($templatePath === false || !is_file($templatePath)) {
            throw new \RuntimeException('Agent Summary Report template not found at ' . self::TEMPLATE_PATH);
        }

        $phpWord = IOFactory::load($templatePath, 'Word2007');
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(9);

        $sections = $phpWord->getSections();
        if (empty($sections)) {
            throw new \RuntimeException('Template has no sections.');
        }
        $section = $sections[0];

        // Title block at top of body
        $titleText = $this->buildTitle($dataSource, $endDate, $continuation, $startDate);
        $section->addText(
            $titleText,
            ['bold' => true, 'size' => 14, 'name' => 'Calibri'],
            ['alignment' => 'center', 'spaceAfter' => 100]
        );

        $section->addText(
            'Period: ' . date('m/d/Y', strtotime($startDate)) . ' – ' . date('m/d/Y', strtotime($endDate)),
            ['size' => 9, 'italic' => true, 'name' => 'Calibri'],
            ['alignment' => 'center', 'spaceAfter' => 200]
        );

        // Table 1: Production (Contacts | Deals | Conversion | Debt)
        $this->buildProductionTable($section, $rows, $continuation);

        // Table 2: Risk (Cancels | NSFs | Cancels % | NSFs %)
        $section->addTextBreak(1);
        $this->buildRiskTable($section, $rows);

        // Table 3: Quality (Avg Debt | SMP)
        $section->addTextBreak(1);
        $this->buildQualityTable($section, $rows);

        return $phpWord;
    }

    private function buildProductionTable(Section $section, array $rows, bool $continuation): void
    {
        $this->addSectionHeading($section, 'Production');

        $blocks = [
            $this->makeBlock('Contacts',   $rows, 'contacts',          'contacts',         'desc', 'int',     false),
            $this->makeBlock('Deals',      $rows, 'deals_current',     'deals_eom',        'desc', 'int',     $continuation),
            $this->makeBlock('Conversion', $rows, 'conversion_current', 'conversion_eom',   'desc', 'percent', true),
            $this->makeBlock('Debt',       $rows, 'debt_current',      'debt_eom',         'desc', 'money_k', true),
        ];

        $this->renderWideTable($section, $blocks);
    }

    private function buildRiskTable(Section $section, array $rows): void
    {
        $this->addSectionHeading($section, 'Risk');

        $blocks = [
            $this->makeBlock('Cancels',   $rows, 'cancels_current',     'cancels_eom',     'asc', 'int',     true),
            $this->makeBlock('NSFs',      $rows, 'nsfs_current',        'nsfs_eom',        'asc', 'int',     true),
            $this->makeBlock('Cancels %', $rows, 'cancels_pct_current', 'cancels_pct_eom', 'asc', 'percent', true),
            $this->makeBlock('NSFs %',    $rows, 'nsfs_pct_current',    'nsfs_pct_eom',    'asc', 'percent', true),
        ];

        $this->renderWideTable($section, $blocks);
    }

    private function buildQualityTable(Section $section, array $rows): void
    {
        $this->addSectionHeading($section, 'Quality');

        $blocks = [
            $this->makeBlock('Average Debt', $rows, 'avg_debt_current', 'avg_debt_eom', 'desc', 'money_k', true),
            $this->makeBlock('SMP',          $rows, 'smp_current',      'smp_eom',      'desc', 'int',     true),
        ];

        $this->renderWideTable($section, $blocks);
    }

    /**
     * Build the metadata for one metric block (header label, sorted agents, format).
     */
    private function makeBlock(
        string $label,
        array $rows,
        string $currentKey,
        string $eomKey,
        string $sortDir,
        string $valueFormat,
        bool $showVariance
    ): array {
        return [
            'label' => $label,
            'sorted' => $this->sortRows($rows, $currentKey, $sortDir),
            'current_key' => $currentKey,
            'eom_key' => $eomKey,
            'value_format' => $valueFormat,
            'show_variance' => $showVariance,
        ];
    }

    /**
     * Render one wide table with N side-by-side metric blocks.
     * Each block contributes [Agent | (Variance) | Value] columns plus a separator between blocks.
     */
    private function renderWideTable(Section $section, array $blocks): void
    {
        if (empty($blocks) || empty($blocks[0]['sorted'])) {
            return;
        }

        $maxRows = 0;
        foreach ($blocks as $block) {
            $maxRows = max($maxRows, count($block['sorted']));
        }

        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => self::BORDER_COLOR,
            'cellMargin' => 60,
            'alignment' => JcTable::CENTER,
        ];

        $table = $section->addTable($tableStyle);

        // ── Header row ──
        $this->addHeaderRow($table, $blocks);

        // ── Data rows ──
        $blockTotals = array_fill(0, count($blocks), 0.0);
        $blockCounts = array_fill(0, count($blocks), 0);

        for ($i = 0; $i < $maxRows; $i++) {
            $isEven = $i % 2 === 1;
            $rowBg = $isEven ? ['bgColor' => self::ALT_ROW_FILL] : [];
            $table->addRow();

            foreach ($blocks as $bi => $block) {
                $rowData = $block['sorted'][$i] ?? null;
                $this->addBlockCells($table, $block, $rowData, $rowBg);

                if ($bi < count($blocks) - 1) {
                    $table->addCell(self::W_SEP, []);
                }

                if ($rowData !== null) {
                    $value = $rowData[$block['current_key']] ?? 0;
                    if (is_numeric($value)) {
                        $blockTotals[$bi] += (float) $value;
                        $blockCounts[$bi]++;
                    }
                }
            }
        }

        // ── Total + Average rows ──
        $this->addSummaryRow($table, $blocks, $blockTotals, $blockCounts, 'Total');
        $this->addSummaryRow($table, $blocks, $blockTotals, $blockCounts, 'Average');
    }

    private function addHeaderRow(Table $table, array $blocks): void
    {
        $headerCellStyle = ['bgColor' => self::HEADER_FILL];
        $headerFontStyle = ['bold' => true, 'color' => self::HEADER_TEXT_COLOR, 'size' => 9];

        $table->addRow();
        foreach ($blocks as $bi => $block) {
            $table->addCell(self::W_AGENT, $headerCellStyle)->addText('Agent', $headerFontStyle);
            if ($block['show_variance']) {
                $table->addCell(self::W_VAR, $headerCellStyle)
                    ->addText('Var', $headerFontStyle, ['alignment' => 'center']);
            } else {
                $table->addCell(self::W_VAR, $headerCellStyle);
            }
            $table->addCell(self::W_VALUE, $headerCellStyle)
                ->addText($block['label'], $headerFontStyle, ['alignment' => 'center']);

            if ($bi < count($blocks) - 1) {
                $table->addCell(self::W_SEP, $headerCellStyle);
            }
        }
    }

    private function addBlockCells(Table $table, array $block, ?array $rowData, array $rowBg): void
    {
        if ($rowData === null) {
            $table->addCell(self::W_AGENT, $rowBg);
            $table->addCell(self::W_VAR, $rowBg);
            $table->addCell(self::W_VALUE, $rowBg);
            return;
        }

        $current = $rowData[$block['current_key']] ?? 0;
        $eom = $rowData[$block['eom_key']] ?? 0;

        $table->addCell(self::W_AGENT, $rowBg)
            ->addText((string) ($rowData['agent'] ?? ''), ['size' => 8]);

        if ($block['show_variance']) {
            $variance = $this->computeVariance($current, $eom, $block['value_format']);
            $table->addCell(self::W_VAR, $rowBg)
                ->addText(
                    $this->formatVariance($variance, $block['value_format']),
                    ['size' => 8],
                    ['alignment' => 'right']
                );
        } else {
            $table->addCell(self::W_VAR, $rowBg);
        }

        $table->addCell(self::W_VALUE, $rowBg)
            ->addText(
                $this->formatValue($current, $block['value_format']),
                ['size' => 8],
                ['alignment' => 'right']
            );
    }

    private function addSummaryRow(
        Table $table,
        array $blocks,
        array $totals,
        array $counts,
        string $label
    ): void {
        $bg = ['bgColor' => self::SUMMARY_FILL];
        $fontStyle = ['bold' => true, 'size' => 8];

        $table->addRow();
        foreach ($blocks as $bi => $block) {
            $table->addCell(self::W_AGENT, $bg)->addText($label, $fontStyle);
            $table->addCell(self::W_VAR, $bg);

            $format = $block['value_format'];
            $showThisRow = $label === 'Average' || in_array($format, ['int', 'money_k'], true);
            $shouldDisplay = $showThisRow && $counts[$bi] > 0;

            if ($shouldDisplay) {
                $value = $label === 'Total' ? $totals[$bi] : ($totals[$bi] / $counts[$bi]);
                $table->addCell(self::W_VALUE, $bg)
                    ->addText($this->formatValue($value, $format), $fontStyle, ['alignment' => 'right']);
            } else {
                $table->addCell(self::W_VALUE, $bg);
            }

            if ($bi < count($blocks) - 1) {
                $table->addCell(self::W_SEP, $bg);
            }
        }
    }

    private function addSectionHeading(Section $section, string $heading): void
    {
        $section->addText(
            $heading,
            ['bold' => true, 'size' => 12, 'color' => self::HEADER_FILL, 'name' => 'Calibri'],
            ['spaceBefore' => 150, 'spaceAfter' => 60]
        );
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
        return $sign . $this->formatAbsValue(abs($variance), $format);
    }

    private function formatValue($value, string $format): string
    {
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
            '--convert-to',
            'pdf',
            '--outdir',
            $outputDir,
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
