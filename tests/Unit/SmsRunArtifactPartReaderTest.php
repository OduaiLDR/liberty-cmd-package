<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunArtifactPartReader;
use Cmd\Reports\Services\SmsRunOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsRunArtifactPartReaderTest extends TestCase
{
    private string $root;
    private string $directory;
    private const ID = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-part-reader-'.bin2hex(random_bytes(8));
        $this->directory = $this->root.'/runs/'.self::ID;
    }

    protected function tearDown(): void
    {
        self::assertMatchesRegularExpression('~/sms-part-reader-[a-f0-9]{16}$~D', $this->root);
        if (! is_dir($this->root)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->root);
    }

    private function bundle(int $cap): array
    {
        $options = SmsRunOptions::normalize(3, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
        $rows = [['Zoë, One', '14000', '02025550100', '2026-10-26'], ["Two\nLines", '14000', '02025550100', '2026-10-26'], ['=Formula', '23000', '02025550102', '2026-10-26']];
        $pending = (new SmsRunArtifactBundle)->build($this->directory, 'SMS Export - 2026-10-26 - ID Pending', $rows, 3, $options);
        $final = (new SmsRunArtifactBundle('v2'))->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
        return [$pending, $final, $options];
    }

    public function test_single_and_split_csv_streams_match_exact_approved_bytes_and_are_private_seekable_then_closed(): void
    {
        foreach ([2, 5] as $cap) {
            $this->directory = $this->root.'/cap-'.$cap.'/runs/'.self::ID;
            [$pending, $final, $options] = $this->bundle($cap);
            $pendingBytes = file_get_contents($pending['path']);
            $expected = [];
            if ($cap === 2) {
                $zip = new \ZipArchive; self::assertTrue($zip->open($final['path']));
                foreach ($final['manifest']['parts'] as $part) $expected[] = $zip->getFromName($part['name']);
                $zip->close();
            } else $expected[] = file_get_contents($final['path']);
            $seen = []; $resources = []; $scratch = [];
            (new SmsRunArtifactPartReader)->eachVerified($this->directory, $final['manifest'], $options, 3,
                static function (array $part, $stream) use (&$seen, &$resources, &$scratch, $expected): void {
                    self::assertTrue(is_resource($stream)); self::assertSame(0, ftell($stream));
                    $meta = stream_get_meta_data($stream); self::assertTrue($meta['seekable']);
                    if (PHP_OS_FAMILY !== 'Windows') self::assertSame(0, fstat($stream)['mode'] & 0077);
                    $bytes = stream_get_contents($stream);
                    self::assertSame($expected[$part['part'] - 1], $bytes);
                    self::assertSame($part['bytes'], strlen($bytes)); self::assertSame($part['sha256'], hash('sha256', $bytes));
                    self::assertStringContainsString('0202555010', $bytes);
                    rewind($stream); self::assertSame($bytes, stream_get_contents($stream));
                    $seen[] = $part; $resources[] = $stream; $scratch[] = $meta['uri'];
                });
            self::assertSame($final['manifest']['parts'], $seen);
            foreach ($resources as $resource) self::assertFalse(is_resource($resource));
            foreach ($scratch as $path) self::assertFileDoesNotExist($path);
            self::assertSame($pendingBytes, file_get_contents($pending['path']));
            self::assertEqualsCanonicalizing(['manifest.json', basename($final['path'])], array_values(array_diff(scandir(dirname($final['path'])), ['.', '..'])));
        }
    }

    public function test_consumer_exception_closes_private_stream_and_removes_scratch_without_changing_bundle(): void
    {
        [, $final, $options] = $this->bundle(2);
        $before = hash_file('sha256', $final['path']); $stream = null; $scratch = null;
        try {
            (new SmsRunArtifactPartReader)->eachVerified($this->directory, $final['manifest'], $options, 3,
                static function (array $part, $input) use (&$stream, &$scratch): void {
                    $stream = $input; $scratch = stream_get_meta_data($input)['uri'];
                    throw new RuntimeException('Synthetic consumer failure.');
                });
            self::fail('Consumer failure should escape.');
        } catch (RuntimeException $error) { self::assertSame('Synthetic consumer failure.', $error->getMessage()); }
        self::assertFalse(is_resource($stream)); self::assertFileDoesNotExist($scratch);
        self::assertSame($before, hash_file('sha256', $final['path']));
    }

    public function test_pending_legacy_and_corrupt_approved_receipts_never_reach_consumer(): void
    {
        [$pending, $final, $options] = $this->bundle(2);
        $cases = [[$pending['manifest'], $options], [$final['manifest'], SmsRunOptions::normalize(3, 2)]];
        $changed = $final['manifest']; $changed['parts'][0]['name'] = '../unexpected.csv'; $cases[] = [$changed, $options];
        $calls = 0;
        foreach ($cases as [$manifest, $caseOptions]) {
            try {
                (new SmsRunArtifactPartReader)->eachVerified($this->directory, $manifest, $caseOptions, 3, static function () use (&$calls): void { $calls++; });
                self::fail('Unsafe publication must fail.');
            } catch (RuntimeException) {}
        }
        file_put_contents($final['path'], 'Synthetic corrupt ZIP');
        try {
            (new SmsRunArtifactPartReader)->eachVerified($this->directory, $final['manifest'], $options, 3, static function () use (&$calls): void { $calls++; });
            self::fail('Corrupt publication must fail.');
        } catch (RuntimeException) {}
        self::assertSame(0, $calls);
    }
}
