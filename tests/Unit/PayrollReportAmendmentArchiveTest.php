<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Tests\TestCase;
use ZipArchive;

class PayrollReportAmendmentArchiveTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { parent::setUp(); $this->directory = sys_get_temp_dir() . '/cs-amend-archive-' . bin2hex(random_bytes(12)); mkdir($this->directory, 0700); }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
        parent::tearDown();
    }
    private function workbook(string $body): array
    {
        $path = $this->directory . '/input-' . bin2hex(random_bytes(4)) . '.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook>' . $body . '</workbook>');
        $zip->close();
        return ['path' => $path, 'filename' => 'Amended.xlsx'];
    }
    public function test_all_nine_amendment_keys_have_immutable_separate_bundle_scoped_storage(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $id = str_repeat('a', 64);
        foreach ([...PayrollReportArtifactArchive::SOURCE_KEYS, ...PayrollReportArtifactArchive::LEADERSHIP_KEYS] as $key) {
            $file = $this->workbook($key);
            $context = ['originalEvidence' => ['component' => $key, 'amount' => -25.55]];
            $saved = $archive->captureAmendment($key, '2098-09-01', $id, $file, $context);
            $this->assertSame($saved, $archive->readAmendment($key, '2098-09-01', $id));
            $this->assertSame($saved, $archive->captureAmendment($key, '2098-09-01', $id, $file, $context));
            $this->assertStringContainsString('/amendments/2098-09-01/' . $id . '/' . $key, $saved['path']);
            $this->assertSame(file_get_contents($file['path']), file_get_contents($saved['path']));
            $this->assertSame($context, $saved['context']);
        }
    }
    public function test_original_source_and_leadership_namespaces_are_unchanged_and_cannot_be_overwritten_by_amendments(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $sourceId = str_repeat('b', 32);
        $bundleId = str_repeat('c', 64);
        $source = $archive->capture('retention_ldr', '2098-09-01', $sourceId, $this->workbook('original source'));
        $leader = $archive->capture('nsf_team_leader', '2098-09-01', $bundleId, $this->workbook('original leader'));
        $sourceBytes = file_get_contents($source['path']);
        $leaderBytes = file_get_contents($leader['path']);
        $archive->captureAmendment('retention_ldr', '2098-09-01', $bundleId, $this->workbook('amended source'));
        $archive->captureAmendment('nsf_team_leader', '2098-09-01', $bundleId, $this->workbook('amended leader'));
        $this->assertSame($sourceBytes, file_get_contents($archive->read('retention_ldr', '2098-09-01', $sourceId)['path']));
        $this->assertSame($leaderBytes, file_get_contents($archive->read('nsf_team_leader', '2098-09-01', $bundleId)['path']));
        $this->expectException(\InvalidArgumentException::class);
        $archive->capture('retention_ldr', '2098-09-01', $bundleId, $this->workbook('invalid source identity'));
    }
    public function test_amendment_identity_scope_content_and_context_are_immutable(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        $id = str_repeat('d', 64);
        $file = $this->workbook('original');
        $saved = $archive->captureAmendment('lookback_ldr', '2098-09-01', $id, $file, ['original' => true]);
        foreach ([[$this->workbook('changed'), ['original' => true]], [$file, ['original' => false]]] as [$next, $context]) {
            try { $archive->captureAmendment('lookback_ldr', '2098-09-01', $id, $next, $context); $this->fail('Published amendment evidence cannot change.'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
        file_put_contents($saved['path'], 'corrupted');
        $this->expectException(\RuntimeException::class);
        $archive->readAmendment('lookback_ldr', '2098-09-01', $id);
    }
    public function test_invalid_keys_months_or_short_source_run_ids_cannot_select_amendment_paths(): void
    {
        $archive = new PayrollReportArtifactArchive($this->directory . '/archive');
        foreach ([['../escape', '2098-09-01', str_repeat('a', 64)], ['nsf_ldr', '2098-09-02', str_repeat('a', 64)], ['nsf_ldr', '2098-09-01', str_repeat('a', 32)]] as $scope) {
            try { $archive->readAmendment(...$scope); $this->fail('Amendment scope must be validated.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
