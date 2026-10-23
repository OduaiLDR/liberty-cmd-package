<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Fresh random local scratch only. No database, provider, identity or mail calls. */
final class SmsRunApprovalArtifactTest extends TestCase
{
    private const ID = '11111111-2222-4333-8444-555555555555';
    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-approval-artifact-'.bin2hex(random_bytes(8));
        $this->directory = $this->root.'/runs/'.self::ID;
    }

    protected function tearDown(): void
    {
        self::assertMatchesRegularExpression('~/sms-approval-artifact-[a-f0-9]{16}$~D', $this->root);
        if (! is_dir($this->root)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function pending(int $cap = 2): array
    {
        $options = SmsRunOptions::normalize(5, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
        $rows = [['Zoë, One', '14000', '02025550100', '2026-10-26'], ["Two\nLines", '23000', '02025550101', '2026-10-26'], ['=formula', '20000', '02025550102', '2026-10-26']];
        $pending = (new SmsRunArtifactBundle)->build($this->directory, 'SMS Export - 2026-10-26 - ID Pending', $rows, 3, $options);
        return [$pending, $options];
    }

    public function test_final_publication_preserves_csv_bytes_for_single_and_split_files_and_replays_exactly(): void
    {
        foreach ([2, 10] as $cap) {
            $this->directory = $this->root.'/cap-'.$cap.'/runs/'.self::ID;
            [$pending, $options] = $this->pending($cap);
            $pendingBytes = file_get_contents($pending['path']);
            $pendingJson = file_get_contents(dirname($pending['path']).'/manifest.json');
            $bundle = new SmsRunArtifactBundle('v2');
            $final = $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
            self::assertStringContainsString('/v2/published/', $final['path']);
            self::assertSame($cap === 2 ? 'SMS Export - 2026-10-26 - SMS0001-SMS0009.zip' : 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 1.csv', basename($final['path']));
            foreach ($pending['manifest']['parts'] as $index => $part) {
                foreach (['part', 'rows', 'bytes', 'sha256'] as $key) self::assertSame($part[$key], $final['manifest']['parts'][$index][$key]);
                self::assertSame('SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part '.($index + 1).' of '.count($pending['manifest']['parts']).'.csv', $final['manifest']['parts'][$index]['name']);
            }
            if ($cap === 2) {
                $sourceZip = new ZipArchive; $finalZip = new ZipArchive;
                self::assertTrue($sourceZip->open($pending['path'])); self::assertTrue($finalZip->open($final['path']));
                try {
                    foreach ($pending['manifest']['parts'] as $index => $part) {
                        self::assertSame($sourceZip->getFromName($part['name']), $finalZip->getFromName($final['manifest']['parts'][$index]['name']));
                    }
                } finally { $sourceZip->close(); $finalZip->close(); }
            } else self::assertSame($pendingBytes, file_get_contents($final['path']));
            self::assertSame($pendingBytes, file_get_contents($pending['path']));
            self::assertSame($pendingJson, file_get_contents(dirname($pending['path']).'/manifest.json'));
            self::assertSame($final, $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009'));
            self::assertSame($final['path'], $bundle->verify($this->directory, $final['manifest'], $options, 3));
            self::assertSame($pending['path'], (new SmsRunArtifactBundle)->verify($this->directory, $pending['manifest'], $options, 3));
        }
    }

    public function test_approval_copy_rejects_changed_range_options_counts_or_corrupt_pending_without_overwrite(): void
    {
        [$pending, $options] = $this->pending();
        $bundle = new SmsRunArtifactBundle('v2');
        $final = $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0007-SMS0007');
        $original = file_get_contents($final['path']);
        foreach ([
            fn () => $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0008-SMS0008'),
            fn () => $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, '../SMS0001-SMS0009'),
            fn () => $bundle->publishApproved($this->directory, $pending['manifest'], $options, 2, 'SMS0007-SMS0007'),
            fn () => (new SmsRunArtifactBundle)->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0007-SMS0007'),
            fn () => $bundle->publishApproved($this->directory, $pending['manifest'], SmsRunOptions::normalize(5, 2), 3, 'SMS0007-SMS0007'),
            fn () => $bundle->build($this->directory, 'SMS Export - 2026-10-26 - SMS0007-SMS0007', [], 3, $options),
        ] as $operation) $this->refuses($operation);
        file_put_contents($pending['path'], 'corrupt pending bytes');
        $this->refuses(fn () => $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0007-SMS0007'));
        self::assertSame($original, file_get_contents($final['path']));
    }

    public function test_partial_owned_final_stage_is_preserved_and_retry_publishes_without_replacing_it(): void
    {
        [$pending, $options] = $this->pending();
        $stage = $this->directory.'/v2/staging-0123456789abcdef';
        mkdir($stage, 0700, true);
        $receipt = ['version' => 1, 'request_id' => self::ID, 'stem' => 'SMS Export - 2026-10-26 - SMS0001-SMS0009', 'parts' => 2];
        file_put_contents($stage.'/.build.json', json_encode($receipt));
        file_put_contents($stage.'/SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv', 'partial private bytes');
        $final = (new SmsRunArtifactBundle('v2'))->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
        self::assertFileExists($final['path']);
        self::assertSame('partial private bytes', file_get_contents($stage.'/SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv'));
        self::assertFileExists($pending['path']);
    }

    public function test_pending_and_approved_generation_names_cannot_be_reinterpreted_as_each_other(): void
    {
        [$pending, $options] = $this->pending();
        $this->refuses(fn () => (new SmsRunArtifactBundle('v2'))->verify($this->directory, $pending['manifest'], $options, 3));
        $this->refuses(fn () => (new SmsRunArtifactBundle)->build($this->root.'/other/runs/'.self::ID, 'SMS Export - 2026-10-26 - SMS0001-SMS0009', [], 3, $options));
        $old = SmsRunOptions::normalize(5, 2, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::DROP_RANGE_FILENAME_VERSION);
        $this->refuses(fn () => (new SmsRunArtifactBundle('v2'))->validateManifest($pending['manifest'], self::ID, $old, 3));
        foreach (['', '../v2', 'v3', 'V2', '/v2'] as $generation) $this->refuses(fn () => new SmsRunArtifactBundle($generation));
    }

    public function test_foreign_final_stage_is_rejected_before_publication(): void
    {
        [$pending, $options] = $this->pending();
        $root = $this->directory.'/v2'; mkdir($root, 0700);
        file_put_contents($root.'/foreign.txt', 'do not replace or delete');
        $this->refuses(fn () => (new SmsRunArtifactBundle('v2'))->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009'));
        self::assertSame('do not replace or delete', file_get_contents($root.'/foreign.txt'));
        self::assertDirectoryDoesNotExist($root.'/published');
    }

    public function test_recovery_rejects_a_self_consistent_final_csv_that_differs_from_pending_bytes(): void
    {
        [$pending, $options] = $this->pending(10);
        $bundle = new SmsRunArtifactBundle('v2');
        $final = $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
        $changed = str_replace('Zoë', 'ZoX', file_get_contents($final['path']));
        file_put_contents($final['path'], $changed);
        $manifest = $final['manifest'];
        foreach (['download', 'parts'] as $section) {
            if ($section === 'download') {
                $manifest[$section]['bytes'] = strlen($changed); $manifest[$section]['sha256'] = hash('sha256', $changed);
            } else {
                $manifest[$section][0]['bytes'] = strlen($changed); $manifest[$section][0]['sha256'] = hash('sha256', $changed);
            }
        }
        file_put_contents(dirname($final['path']).'/manifest.json', json_encode($manifest));
        // Its own receipt is valid, but adoption must additionally pin pending CSV hashes.
        self::assertSame($final['path'], $bundle->verify($this->directory, $manifest, $options, 3));
        $this->refuses(fn () => $bundle->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009'));
        self::assertSame($changed, file_get_contents($final['path']));
        self::assertSame($pending['sha256'], hash_file('sha256', $pending['path']));
    }

    private function refuses(callable $operation): void
    {
        try { $operation(); self::fail('Expected safe SMS artifact refusal.'); }
        catch (RuntimeException) { self::assertTrue(true); }
    }
}
