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
 * Strategy: build the body content (title + 3 wide tables) into a fresh PhpWord docx,
 * then surgically replace ONLY word/document.xml inside a copy of the LDR template.
 * This preserves the template's VML watermark, header (logo + contact info), footer
 * disclaimer, and section properties exactly as they are in the original docx.
 * PhpWord's IOFactory::load round-trip mangles VML watermarks, so we avoid loading
 * the template entirely.
 */
class ReportBuilder
{
    private const TEMPLATE_PATH = __DIR__ . '/../../../../resources/templates/Agent Summary Report Template.docx';

    private const HEADER_FILL = '17853B';
    private const HEADER_TEXT_COLOR = 'FFFFFF';
    private const SUMMARY_FILL = 'E0E6EC';
    private const ALT_ROW_FILL = 'F5F7FA';
    private const BORDER_COLOR = '999999';

    // Column widths in twips. Total per 4-block table:
    //   4 * (1700 + 800 + 1100) + 3 * 200 = 14600 - 200 = 14400 usable (landscape Letter, 720 margins).
    private const W_AGENT = 1700;
    private const W_VAR = 800;
    private const W_VALUE = 1100;
    private const W_SEP = 200;

    public function build(
        array $agentMetrics,
        string $dataSource,
        string $startDate,
        string $endDate,
        bool $continuation
    ): array {
        $filename = $this->buildFilename($dataSource, $startDate, $continuation);
        $pdfPath = storage_path('app/' . $filename);
        $docxFilename = preg_replace('/\.pdf$/', '.docx', $filename);
        $finalDocxPath = storage_path('app/' . $docxFilename);

        $templatePath = realpath(self::TEMPLATE_PATH);
        if ($templatePath === false || !is_file($templatePath)) {
            throw new \RuntimeException('Agent Summary Report template not found at ' . self::TEMPLATE_PATH);
        }

        // 1. Build a fresh PhpWord doc with only our body content
        $phpWord = $this->buildBodyDocument($agentMetrics, $dataSource, $startDate, $endDate, $continuation);

        $tempContentDocx = storage_path('app/_content-' . uniqid() . '.docx');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempContentDocx);

        try {
            // 2. Extract the body content (paragraphs + tables, no sectPr) from generated docx
            $generatedDocXml = $this->readDocXmlFromDocx($tempContentDocx);
            $bodyInner = $this->extractBodyInner($generatedDocXml);

            // 3. Copy template, then patch its document.xml to keep template's namespaces,
            //    template's sectPr (headers/footers/page setup), with our generated body content.
            copy($templatePath, $finalDocxPath);
            $templateDocXml = $this->readDocXmlFromDocx($finalDocxPath);
            $newDocXml = $this->patchDocumentXml($templateDocXml, $bodyInner);
            $this->writeDocXmlToDocx($finalDocxPath, $newDocXml);

            // 4. Convert to PDF via LibreOffice
            $this->convertToPdf($finalDocxPath, dirname($pdfPath));
        } finally {
            if (is_file($tempContentDocx)) {
                @unlink($tempContentDocx);
            }
            if (is_file($finalDocxPath)) {
                @unlink($finalDocxPath);
            }
        }

        if (!is_file($pdfPath)) {
            throw new \RuntimeException("PDF conversion failed; expected file not found at {$pdfPath}");
        }

        return [
            'filename' => $filename,
            'path' => $pdfPath,
        ];
    }

    private function buildBodyDocument(
        array $rows,
        string $dataSource,
        string $startDate,
        string $endDate,
        bool $continuation
    ): PhpWord {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(9);

        // Page setup matches the template (landscape Letter, slim margins)
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 15840,
            'pageSizeH' => 12240,
            'marginLeft' => 720,
            'marginRight' => 720,
            'marginTop' => 720,
            'marginBottom' => 720,
        ]);

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

        $this->buildProductionTable($section, $rows, $continuation);
        $section->addTextBreak(1);
        $this->buildRiskTable($section, $rows);
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

        // Quality has only 2 blocks; widen each so the page is used efficiently.
        $blocks = [
            $this->makeBlock('Average Debt', $rows, 'avg_debt_current', 'avg_debt_eom', 'desc', 'money_k', true),
            $this->makeBlock('SMP',          $rows, 'smp_current',      'smp_eom',      'desc', 'int',     true),
        ];

        $this->renderWideTable($section, $blocks, true);
    }

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

    private function renderWideTable(Section $section, array $blocks, bool $wideMode = false): void
    {
        if (empty($blocks) || empty($blocks[0]['sorted'])) {
            return;
        }

        $maxRows = 0;
        foreach ($blocks as $block) {
            $maxRows = max($maxRows, count($block['sorted']));
        }

        $wAgent = $wideMode ? 3200 : self::W_AGENT;
        $wVar = $wideMode ? 1200 : self::W_VAR;
        $wValue = $wideMode ? 2000 : self::W_VALUE;
        $wSep = self::W_SEP;

        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => self::BORDER_COLOR,
            'cellMargin' => 60,
            'alignment' => JcTable::CENTER,
        ];

        $table = $section->addTable($tableStyle);

        // Header row (will repeat on each page break)
        $this->addHeaderRow($table, $blocks, $wAgent, $wVar, $wValue, $wSep);

        // Data rows
        $blockTotals = array_fill(0, count($blocks), 0.0);
        $blockCounts = array_fill(0, count($blocks), 0);

        for ($i = 0; $i < $maxRows; $i++) {
            $isEven = $i % 2 === 1;
            $rowBg = $isEven ? ['bgColor' => self::ALT_ROW_FILL] : [];
            $table->addRow();

            foreach ($blocks as $bi => $block) {
                $rowData = $block['sorted'][$i] ?? null;
                $this->addBlockCells($table, $block, $rowData, $rowBg, $wAgent, $wVar, $wValue);

                if ($bi < count($blocks) - 1) {
                    $table->addCell($wSep, []);
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

        // Summary rows
        $this->addSummaryRow($table, $blocks, $blockTotals, $blockCounts, 'Total', $wAgent, $wVar, $wValue, $wSep);
        $this->addSummaryRow($table, $blocks, $blockTotals, $blockCounts, 'Average', $wAgent, $wVar, $wValue, $wSep);
    }

    private function addHeaderRow(Table $table, array $blocks, int $wAgent, int $wVar, int $wValue, int $wSep): void
    {
        $headerCellStyle = ['bgColor' => self::HEADER_FILL];
        $headerFontStyle = ['bold' => true, 'color' => self::HEADER_TEXT_COLOR, 'size' => 9];

        // tblHeader => true: repeat row on page break
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($blocks as $bi => $block) {
            $table->addCell($wAgent, $headerCellStyle)->addText('Agent', $headerFontStyle);
            if ($block['show_variance']) {
                $table->addCell($wVar, $headerCellStyle)
                    ->addText('Var', $headerFontStyle, ['alignment' => 'center']);
            } else {
                $table->addCell($wVar, $headerCellStyle);
            }
            $table->addCell($wValue, $headerCellStyle)
                ->addText($block['label'], $headerFontStyle, ['alignment' => 'center']);

            if ($bi < count($blocks) - 1) {
                $table->addCell($wSep, $headerCellStyle);
            }
        }
    }

    private function addBlockCells(
        Table $table,
        array $block,
        ?array $rowData,
        array $rowBg,
        int $wAgent,
        int $wVar,
        int $wValue
    ): void {
        if ($rowData === null) {
            $table->addCell($wAgent, $rowBg);
            $table->addCell($wVar, $rowBg);
            $table->addCell($wValue, $rowBg);
            return;
        }

        $current = $rowData[$block['current_key']] ?? 0;
        $eom = $rowData[$block['eom_key']] ?? 0;

        $table->addCell($wAgent, $rowBg)
            ->addText((string) ($rowData['agent'] ?? ''), ['size' => 8]);

        if ($block['show_variance']) {
            $variance = $this->computeVariance($current, $eom, $block['value_format']);
            $table->addCell($wVar, $rowBg)
                ->addText(
                    $this->formatVariance($variance, $block['value_format']),
                    ['size' => 8],
                    ['alignment' => 'right']
                );
        } else {
            $table->addCell($wVar, $rowBg);
        }

        $table->addCell($wValue, $rowBg)
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
        string $label,
        int $wAgent,
        int $wVar,
        int $wValue,
        int $wSep
    ): void {
        $bg = ['bgColor' => self::SUMMARY_FILL];
        $fontStyle = ['bold' => true, 'size' => 8];

        $table->addRow();
        foreach ($blocks as $bi => $block) {
            $table->addCell($wAgent, $bg)->addText($label, $fontStyle);
            $table->addCell($wVar, $bg);

            $format = $block['value_format'];
            $showThisRow = $label === 'Average' || in_array($format, ['int', 'money_k'], true);
            $shouldDisplay = $showThisRow && $counts[$bi] > 0;

            if ($shouldDisplay) {
                $value = $label === 'Total' ? $totals[$bi] : ($totals[$bi] / $counts[$bi]);
                $table->addCell($wValue, $bg)
                    ->addText($this->formatValue($value, $format), $fontStyle, ['alignment' => 'right']);
            } else {
                $table->addCell($wValue, $bg);
            }

            if ($bi < count($blocks) - 1) {
                $table->addCell($wSep, $bg);
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

    // ────────────────────────────────────────────────────────────────────────────
    // ZIP-level docx manipulation: surgically replace body while preserving the
    // template's headers, footers, watermark, and section properties.
    // ────────────────────────────────────────────────────────────────────────────

    private function readDocXmlFromDocx(string $docxPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException("Cannot open docx: {$docxPath}");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException("word/document.xml missing in {$docxPath}");
        }
        return $xml;
    }

    private function writeDocXmlToDocx(string $docxPath, string $newXml): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException("Cannot open docx for write: {$docxPath}");
        }
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();
    }

    /**
     * Extract the body content (everything inside <w:body> excluding the trailing <w:sectPr>).
     */
    private function extractBodyInner(string $xml): string
    {
        if (!preg_match('/<w:body[^>]*>(.*)<\/w:body>/s', $xml, $m)) {
            throw new \RuntimeException('Could not locate <w:body> in generated content docx');
        }
        $bodyContents = $m[1];

        // Strip any <w:sectPr ...>...</w:sectPr> at the end (or self-closing variant)
        $bodyContents = preg_replace('/<w:sectPr\b.*?<\/w:sectPr>\s*$/s', '', $bodyContents);
        $bodyContents = preg_replace('/<w:sectPr\b[^>]*\/>\s*$/s', '', $bodyContents);

        return trim((string) $bodyContents);
    }

    /**
     * Build new document.xml: keep template's outer wrapper and sectPr (with headers/footers
     * references intact), and substitute the body content with ours.
     */
    private function patchDocumentXml(string $templateXml, string $newBodyInner): string
    {
        if (!preg_match('/<w:sectPr\b.*?<\/w:sectPr>|<w:sectPr\b[^>]*\/>/s', $templateXml, $sectMatch)) {
            throw new \RuntimeException('Template has no <w:sectPr> — cannot preserve page setup.');
        }
        $sectPr = $sectMatch[0];

        $newBody = '<w:body>' . $newBodyInner . $sectPr . '</w:body>';

        $patched = preg_replace('/<w:body[^>]*>.*<\/w:body>/s', $newBody, $templateXml, 1);
        if ($patched === null) {
            throw new \RuntimeException('Failed to substitute <w:body> in template.');
        }
        return $patched;
    }

    // ────────────────────────────────────────────────────────────────────────────
    // PDF conversion
    // ────────────────────────────────────────────────────────────────────────────

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
            Log::error('LibreOffice PDF conversion failed.', [
                'docx' => $docxPath,
                'output_dir' => $outputDir,
                'binary' => $binary,
                'exit_code' => $process->getExitCode(),
                'stderr' => $stderr,
                'stdout' => $process->getOutput(),
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
