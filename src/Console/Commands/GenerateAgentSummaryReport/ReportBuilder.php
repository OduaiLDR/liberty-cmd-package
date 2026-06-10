<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\SimpleType\JcTable;
use Symfony\Component\Process\Process;

/**
 * Builds the Agent Summary Report PDF to match Jacob's reference layout:
 *   - Title at top
 *   - Each "metric" is its own block: large bold title + 2-col table (Assigned to Agent | Value)
 *   - 4 blocks side-by-side per page (Production, Risk) and 2 blocks on the Quality page
 *   - No variance column, no section groupings
 *   - Blue (#4F81BD) sub-header on the inner table
 *
 * Watermark/header/footer come from the LDR template — we surgically replace only
 * word/document.xml inside the template docx, leaving headers/footers/images untouched.
 */
class ReportBuilder
{
    private const TEMPLATE_PATH = __DIR__ . '/../../../../resources/templates/Agent Summary Report Template.docx';

    private const HEADER_BG = '4F81BD';
    private const HEADER_TEXT = 'FFFFFF';
    private const SUMMARY_BG = 'E0E6EC';
    private const ALT_ROW_BG = 'F5F7FA';
    private const BORDER_COLOR = '999999';

    private const BLOCK_WIDTH = 3300;
    private const W_AGENT = 1900;
    private const W_VALUE = 1400;
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

        $phpWord = $this->buildBodyDocument($agentMetrics, $dataSource, $startDate, $endDate, $continuation);

        $tempContentDocx = storage_path('app/_content-' . uniqid() . '.docx');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempContentDocx);

        try {
            $generatedDocXml = $this->readDocXmlFromDocx($tempContentDocx);
            $bodyInner = $this->extractBodyInner($generatedDocXml);

            copy($templatePath, $finalDocxPath);
            $templateDocXml = $this->readDocXmlFromDocx($finalDocxPath);
            $newDocXml = $this->patchDocumentXml($templateDocXml, $bodyInner);
            $this->writeDocXmlToDocx($finalDocxPath, $newDocXml);

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

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 15840,
            'pageSizeH' => 12240,
            'marginLeft' => 720,
            'marginRight' => 720,
            'marginTop' => 720,
            'marginBottom' => 720,
        ]);

        // Title
        $titleText = 'Agent Summary Report - ' . date('n/j/Y', strtotime($endDate));
        if ($continuation) {
            $titleText .= ' (' . date('F Y', strtotime($startDate)) . ')';
        }
        $titleText .= ' - ' . $dataSource;

        $section->addText(
            $titleText,
            ['bold' => true, 'size' => 14, 'name' => 'Calibri'],
            ['alignment' => 'center', 'spaceAfter' => 240]
        );

        // ── Page 1: Production (4 blocks) ──
        $this->buildMetricRow($section, $rows, [
            ['Total Contacts',   'contacts',            'desc', 'int',     'Count'],
            ['Total Deals',      'deals_current',       'desc', 'int',     'Count'],
            ['Conversion Ratio', 'conversion_current',  'desc', 'percent', '%'],
            ['Total Debt',       'debt_current',        'desc', 'money_k', 'Sum'],
        ]);

        $section->addPageBreak();

        // ── Page 2: Risk (4 blocks) ──
        $this->buildMetricRow($section, $rows, [
            ['Total Cancels', 'cancels_current',     'asc', 'int',     'Count'],
            ['Total NSFs',    'nsfs_current',        'asc', 'int',     'Count'],
            ['Cancel %',      'cancels_pct_current', 'asc', 'percent', '%'],
            ['NSF %',         'nsfs_pct_current',    'asc', 'percent', '%'],
        ]);

        $section->addPageBreak();

        // ── Page 3: Quality (2 blocks) ──
        $this->buildMetricRow($section, $rows, [
            ['Average Debt',   'avg_debt_current', 'desc', 'money_k', 'Average'],
            ['Same Month Pay', 'smp_current',      'desc', 'int',     'Count'],
        ]);

        return $phpWord;
    }

    /**
     * Build one row of metric blocks side-by-side via an outer (invisible) table.
     * Each cell contains a large title + inner table.
     *
     * @param array $configs Each entry: [title, valueKey, sortDir, format, valueHeader]
     */
    private function buildMetricRow(Section $section, array $rows, array $configs): void
    {
        $outerStyle = [
            'cellMargin' => 0,
            'alignment' => JcTable::CENTER,
        ];

        $outer = $section->addTable($outerStyle);
        $outer->addRow();

        $lastIndex = count($configs) - 1;
        foreach ($configs as $i => $config) {
            [$title, $valueKey, $sortDir, $format, $valueHeader] = $config;

            $cell = $outer->addCell(self::BLOCK_WIDTH, ['valign' => 'top']);

            // Block title (large bold centered)
            $cell->addText(
                $title,
                ['bold' => true, 'size' => 14, 'name' => 'Calibri'],
                ['alignment' => 'center', 'spaceAfter' => 40]
            );

            $this->buildBlockTable($cell, $rows, $valueKey, $sortDir, $format, $valueHeader);

            // Empty separator cell between blocks for visual gap
            if ($i < $lastIndex) {
                $outer->addCell(self::W_SEP, ['valign' => 'top']);
            }
        }
    }

    private function buildBlockTable(
        $cell,
        array $rows,
        string $valueKey,
        string $sortDir,
        string $format,
        string $valueHeader
    ): void {
        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => self::BORDER_COLOR,
            'cellMargin' => 20,
            'alignment' => JcTable::CENTER,
        ];

        $table = $cell->addTable($tableStyle);

        // Sub-header row (blue background, white text, repeats on page break)
        $headerBg = ['bgColor' => self::HEADER_BG];
        $headerFont = ['bold' => true, 'color' => self::HEADER_TEXT, 'size' => 9];

        $table->addRow(null, ['tblHeader' => true]);
        $table->addCell(self::W_AGENT, $headerBg)
            ->addText('Assigned to Agent', $headerFont);
        $table->addCell(self::W_VALUE, $headerBg)
            ->addText($valueHeader, $headerFont, ['alignment' => 'center']);

        // Sort + data rows
        $sorted = $this->sortRows($rows, $valueKey, $sortDir);

        $total = 0.0;
        $count = 0;
        foreach ($sorted as $i => $row) {
            $bg = ($i % 2 === 1) ? ['bgColor' => self::ALT_ROW_BG] : [];
            $table->addRow();
            $table->addCell(self::W_AGENT, $bg)
                ->addText((string) ($row['agent'] ?? ''), ['size' => 8]);

            $value = $row[$valueKey] ?? 0;
            $table->addCell(self::W_VALUE, $bg)
                ->addText(
                    $this->formatValue($value, $format),
                    ['size' => 8],
                    ['alignment' => 'right']
                );

            if (is_numeric($value)) {
                $total += (float) $value;
                $count++;
            }
        }

        // Total + Average rows (always emit both so all tables have matching row counts)
        if ($count > 0) {
            $sumBg = ['bgColor' => self::SUMMARY_BG];
            $sumFont = ['bold' => true, 'size' => 8];

            // Total row — emit for all formats. For percent, value cell is left blank
            // (summing percentages is meaningless) but the row is present so all tables align.
            $table->addRow();
            $table->addCell(self::W_AGENT, $sumBg)->addText('Total', $sumFont);
            if (in_array($format, ['int', 'money_k'], true)) {
                $table->addCell(self::W_VALUE, $sumBg)->addText(
                    $this->formatValue($total, $format),
                    $sumFont,
                    ['alignment' => 'right']
                );
            } else {
                $table->addCell(self::W_VALUE, $sumBg);
            }

            // Average row
            $table->addRow();
            $table->addCell(self::W_AGENT, $sumBg)->addText('Average', $sumFont);
            $table->addCell(self::W_VALUE, $sumBg)->addText(
                $this->formatValue($total / $count, $format),
                $sumFont,
                ['alignment' => 'right']
            );
        }
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

    private function formatValue($value, string $format): string
    {
        $num = is_numeric($value) ? (float) $value : 0;

        switch ($format) {
            case 'percent':
                return number_format($num * 100, 2) . '%';
            case 'money_k':
                return '$' . number_format($num / 1000) . 'k';
            case 'int':
            default:
                return number_format($num);
        }
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

    private function extractBodyInner(string $xml): string
    {
        if (!preg_match('/<w:body[^>]*>(.*)<\/w:body>/s', $xml, $m)) {
            throw new \RuntimeException('Could not locate <w:body> in generated content docx');
        }
        $bodyContents = $m[1];

        $bodyContents = preg_replace('/<w:sectPr\b.*?<\/w:sectPr>\s*$/s', '', $bodyContents);
        $bodyContents = preg_replace('/<w:sectPr\b[^>]*\/>\s*$/s', '', $bodyContents);

        return trim((string) $bodyContents);
    }

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
