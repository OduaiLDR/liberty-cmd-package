<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Tests\TestCase;
use ZipArchive;

class PayrollReportArtifactArchiveTest extends TestCase
{
    private string $directory;
    private array $environment = [];
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/payroll-artifact-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        foreach (['PAYROLL_COMMISSION_RUN_ID', 'PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT'] as $key) $this->environment[$key] = getenv($key);
    }
    protected function tearDown(): void
    {
        $remove = static function ($path) use (&$remove): void {
            foreach (scandir($path) as $name) {
                if ($name === '.' || $name === '..') continue;
                $child = $path . '/' . $name;
                is_dir($child) && !is_link($child) ? $remove($child) : unlink($child);
            }
            rmdir($path);
        };
        $remove($this->directory);
        foreach ($this->environment as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
        parent::tearDown();
    }
    private function workbook(string $body = 'known source'): array
    {
        $path = $this->directory . '/source-' . bin2hex(random_bytes(4)) . '.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<workbook>' . $body . '</workbook>');
        $zip->close();
        return ['path' => $path, 'filename' => 'Retention Commission (LDR) - All.xlsx'];
    }
    public function test_default_sales_refresh_never_reads_workbook_or_requests_leadership_context(): void
    {
        putenv('PAYROLL_COMMISSION_RUN_ID=' . str_repeat('a', 32));
        foreach ([null, '0', 'false'] as $flag) {
            $flag === null ? putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT') : putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=' . $flag);
            $this->assertFalse(PayrollReportArtifactArchive::captureRequested());
            $this->assertNull(PayrollReportArtifactArchive::captureFromEnvironment('retention', 'ldr', '2098-09-01', ['path' => '/missing.xlsx']));
        }
    }
    public function test_customer_service_capture_requires_explicit_flag_and_reserved_run_identity(): void
    {
        putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=1');
        putenv('PAYROLL_COMMISSION_RUN_ID=' . str_repeat('a', 32));
        $this->assertTrue(PayrollReportArtifactArchive::captureRequested());
        putenv('PAYROLL_COMMISSION_RUN_ID=unreserved');
        $this->expectException(\InvalidArgumentException::class);
        PayrollReportArtifactArchive::captureRequested();
    }
    public function test_explicit_customer_service_capture_preserves_actual_workbook_and_context(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $container = new class($this->directory) extends \Illuminate\Container\Container {
            public function __construct(private string $root) {}
            public function storagePath($path = ''): string { return $this->root . ($path === '' ? '' : '/' . $path); }
        };
        \Illuminate\Container\Container::setInstance($container);
        try {
            putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=1');
            putenv('PAYROLL_COMMISSION_RUN_ID=' . str_repeat('a', 32));
            $file = $this->workbook();
            $context = ['trancheContextReady' => true, 'trancheByClient' => [
                'LLG-101' => ['TRANCHE' => '53', 'CUT_OFF' => '2098-10-18', 'MADE_CUT_OFF' => true, 'BONUS_FLAG' => 'Bonus'],
                'LLG-102' => ['TRANCHE' => '', 'CUT_OFF' => null, 'MADE_CUT_OFF' => false, 'BONUS_FLAG' => ''],
            ]];
            $saved = PayrollReportArtifactArchive::captureFromEnvironment('retention', 'ldr', '2098-09-01', $file, $context);
            $this->assertSame($context, $saved['context']);
            $this->assertSame(hash_file('sha256', $file['path']), $saved['sha256']);
            $this->assertSame(file_get_contents($file['path']), file_get_contents($saved['path']));
            $read = (new PayrollReportArtifactArchive)->read('retention_ldr', '2098-09-01', str_repeat('a', 32));
            $this->assertSame($context, $read['context']);
            $this->assertSame($saved['contextSha256'], $read['contextSha256']);
        } finally { \Illuminate\Container\Container::setInstance($previous); }
    }
    public function test_source_archive_retains_exact_workbook_after_temporary_source_is_deleted(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $file = $this->workbook();
        $bytes = file_get_contents($file['path']);
        $saved = $archive->capture('retention_ldr', '2098-09-01', str_repeat('a', 32), $file);
        unlink($file['path']);
        $read = $archive->read('retention_ldr', '2098-09-01', str_repeat('a', 32));
        $this->assertSame($bytes, file_get_contents($read['path']));
        $this->assertSame(hash('sha256', $bytes), $saved['sha256']);
        $this->assertSame(strlen($bytes), $saved['byteLength']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $saved['contentType']);
        $this->assertSame($file['filename'], $saved['fileName']);
    }
    public function test_existing_identity_is_immutable_but_same_bytes_can_be_retried(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $file = $this->workbook();
        $first = $archive->capture('lookback_plaw', '2098-09-01', str_repeat('b', 32), $file);
        $this->assertSame($first, $archive->capture('lookback_plaw', '2098-09-01', str_repeat('b', 32), $file));
        $this->expectException(\RuntimeException::class);
        $archive->capture('lookback_plaw', '2098-09-01', str_repeat('b', 32), $this->workbook('changed data'));
    }
    public function test_same_workbook_cannot_replace_an_existing_historical_cutoff_context(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $file = $this->workbook();
        $context = ['trancheContextReady' => true, 'trancheByClient' => [
            'LLG-101' => ['TRANCHE' => '53', 'CUT_OFF' => '2098-10-18', 'MADE_CUT_OFF' => true, 'BONUS_FLAG' => 'Bonus'],
        ]];
        $archive->capture('retention_ldr', '2098-09-01', str_repeat('a', 32), $file, $context);
        $changed = $context;
        $changed['trancheByClient']['LLG-101']['CUT_OFF'] = '2098-11-18';
        try {
            $archive->capture('retention_ldr', '2098-09-01', str_repeat('a', 32), $file, $changed);
            $this->fail('Current cutoff replaced an immutable historical snapshot.');
        } catch (\RuntimeException) {
            $this->assertSame($context, $archive->read('retention_ldr', '2098-09-01', str_repeat('a', 32))['context']);
        }
    }

    public function test_scope_paths_and_invalid_non_workbook_bytes_are_rejected(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        foreach ([['../escape', '2098-09-01', str_repeat('a', 32)], ['nsf_ldr', '2098-09-31', str_repeat('a', 32)], ['nsf_ldr', '2098-09-01', '../escape']] as $scope) {
            try { $archive->read(...$scope); $this->fail('Caller supplied paths must be rejected.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $file = $this->workbook();
        file_put_contents($file['path'], 'not an XLSX');
        $this->expectException(\RuntimeException::class);
        $archive->capture('nsf_ldr', '2098-09-01', str_repeat('c', 32), $file);
    }
    public function test_changed_archived_bytes_fail_verification_instead_of_returning_a_new_report(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $saved = $archive->capture('nsf_ldr', '2098-09-01', str_repeat('a', 32), $this->workbook());
        file_put_contents($saved['path'], 'corrupt archived data');
        $this->expectException(\RuntimeException::class);
        $archive->read('nsf_ldr', '2098-09-01', str_repeat('a', 32));
    }
}
