<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\LegacyRetentionSourceContext;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LegacyRetentionSourceContextTest extends TestCase
{
    public function test_detail_only_agents_remain_unknown_and_not_zero_filled(): void
    {
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet();
        $sheet->setCellValue('C1', 'Retention Agent')->setCellValue('D1', 'Retention Date')->setCellValue('J1', 'Retention Payment Date');
        $sheet->setCellValue('C2', 'Known')->setCellValue('D2', '2026-08-05')->setCellValue('J2', '2026-10-08');
        $sheet->setCellValue('C3', 'Unknown')->setCellValue('D3', '2026-08-05')->setCellValue('J3', '2026-10-08');
        $sheet->setCellValue('C4', 'unknown')->setCellValue('D4', '2026-08-05')->setCellValue('J4', '2026-11-01');
        $entries = LegacyRetentionSourceContext::detailOnlyAgents($sheet, [['agent' => 'Known']], '2026-10-01');
        self::assertCount(1, $entries);
        self::assertSame('Unknown', $entries[0]['agent']);
        self::assertSame(2, $entries[0]['detailRows']);
        self::assertSame(1, $entries[0]['potentialPayableRows']);
        self::assertNull($entries[0]['amount']);
        $book->disconnectWorksheets();
    }

    public function test_invalid_period_does_not_read_or_create_source_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LegacyRetentionSourceContext::build('/nonexistent', 'ldr', '2026-11-01');
    }

    public function test_archive_rejects_changed_input_before_reserving_legacy_identity(): void
    {
        $root = sys_get_temp_dir() . '/legacy-retention-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $path = $root . '/source.xlsx'; $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>'); $zip->addFromString('xl/workbook.xml', '<workbook/>'); $zip->close();
        $archiveRoot = $root . '/archive'; $archive = new PayrollReportArtifactArchive($archiveRoot);
        $context = ['legacyEvidence' => ['source' => 'ldr', 'period' => '2026-10-01', 'workbookSha256' => str_repeat('0', 64), 'coverage' => 'unverified', 'historicalLinePricing' => 'unverified', 'executionReceipt' => 'unverified', 'eligibleForPayment' => false]];
        try {
            try { $archive->capture('retention_ldr', '2026-10-01', str_repeat('a', 32), ['path' => $path, 'filename' => 'source.xlsx'], $context); self::fail('Different bytes accepted'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('Legacy evidence', $error->getMessage()); }
            self::assertDirectoryDoesNotExist($archiveRoot);
        } finally { unlink($path); rmdir($root); }
    }

    public function test_actual_scope_requires_exact_known_hash_and_workbook_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-retention-');
        try {
            file_put_contents($path, 'not an original workbook');
            $this->expectException(\RuntimeException::class);
            LegacyRetentionSourceContext::build($path, 'ldr', '2026-10-01');
        } finally { unlink($path); }
    }

    public function test_legacy_evidence_cannot_claim_complete_results(): void
    {
        $root = sys_get_temp_dir() . '/legacy-retention-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
        $path = $root . '/source.xlsx'; $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>'); $zip->addFromString('xl/workbook.xml', '<workbook/>'); $zip->close();
        $context = ['legacyEvidence' => ['source' => 'ldr', 'period' => '2026-10-01', 'workbookSha256' => hash_file('sha256', $path), 'coverage' => 'unverified', 'historicalLinePricing' => 'unverified', 'executionReceipt' => 'unverified', 'eligibleForPayment' => false], 'commissionResults' => ['complete' => true]];
        try {
            try { (new PayrollReportArtifactArchive($root . '/archive'))->capture('retention_ldr', '2026-10-01', str_repeat('a', 32), ['path' => $path, 'filename' => 'source.xlsx'], $context); self::fail('Complete results accepted'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('Legacy evidence', $error->getMessage()); }
            self::assertDirectoryDoesNotExist($root . '/archive');
        } finally { unlink($path); rmdir($root); }
    }

    public function test_incomplete_legacy_context_cannot_enter_complete_bundle_merge(): void
    {
        $this->expectExceptionMessage('Exact complete source commission components are required');
        PayrollCommissionResultSnapshot::merge(['retention_ldr' => ['context' => ['legacyEvidence' => ['coverage' => 'unverified']]]], '2026-10-01');
    }
}
