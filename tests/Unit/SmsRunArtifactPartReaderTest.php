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
        if (!mkdir($this->root, 0700)) throw new RuntimeException('Unable to create the owned private test root.');
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

    public function test_bounded_reader_yields_only_the_requested_exact_part_and_closes_scratch(): void
    {
        foreach ([2, 5] as $cap) {
            $this->directory = $this->root.'/one-'.$cap.'/runs/'.self::ID;
            [, $final, $options] = $this->bundle($cap);
            $number = count($final['manifest']['parts']);
            $original = hash_file('sha256', $final['path']);
            $calls = 0; $held = null; $scratch = null;
            (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, $number,
                static function (array $part, $stream) use ($number, &$calls, &$held, &$scratch): void {
                    $calls++; $held = $stream; $scratch = stream_get_meta_data($stream)['uri'];
                    self::assertSame($number, $part['part']); self::assertSame(0, ftell($stream));
                    self::assertSame($part['sha256'], hash('sha256', stream_get_contents($stream)));
                });
            self::assertSame(1, $calls); self::assertFalse(is_resource($held));
            self::assertFileDoesNotExist($scratch); self::assertSame($original, hash_file('sha256', $final['path']));
        }
    }

    public function test_bounded_csv_proof_does_not_claim_a_fresh_whole_zip_digest(): void
    {
        [, $final, $options] = $this->bundle(2);
        $zip = new \ZipArchive; self::assertTrue($zip->open($final['path']));
        $name = $final['manifest']['parts'][1]['name'];
        $bytes = $zip->getFromName($name); $changed = 'X'.substr($bytes, 1);
        self::assertTrue($zip->addFromString($name, $changed));
        self::assertTrue($zip->setCompressionName($name, \ZipArchive::CM_STORE));
        self::assertTrue($zip->setMtimeName($name, 315532800)); $zip->close();
        clearstatcache(true, $final['path']);
        self::assertSame($final['manifest']['download']['bytes'], filesize($final['path']));
        $calls = 0;
        (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
            static function () use (&$calls): void { $calls++; });
        self::assertSame(1, $calls);
        try {
            (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 2,
                static function () use (&$calls): void { $calls++; });
            self::fail('Changed selected bytes must not reach the consumer.');
        } catch (RuntimeException) {}
        self::assertSame(1, $calls);
        $this->expectException(RuntimeException::class);
        (new SmsRunArtifactBundle('v2'))->verify($this->directory, $final['manifest'], $options, 3);
    }

    public function test_bounded_reader_rejects_bad_ordinals_and_never_exposes_scratch_on_failure(): void
    {
        [, $final, $options] = $this->bundle(2);
        $calls = 0;
        foreach ([0, 3] as $number) {
            try {
                (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, $number,
                    static function () use (&$calls): void { $calls++; });
                self::fail('Unverified part cannot be read.');
            } catch (RuntimeException) {}
        }
        $held = null; $scratch = null;
        try {
            (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
                static function (array $part, $stream) use (&$held, &$scratch): void {
                    $held = $stream; $scratch = stream_get_meta_data($stream)['uri'];
                    throw new RuntimeException('Synthetic consumer failure.');
                });
            self::fail('Expected consumer failure.');
        } catch (RuntimeException $error) { self::assertSame('Synthetic consumer failure.', $error->getMessage()); }
        self::assertSame(0, $calls); self::assertFalse(is_resource($held)); self::assertFileDoesNotExist($scratch);
    }

    public function test_bounded_reader_refuses_an_exclusively_locked_source_without_waiting(): void
    {
        [, $final, $options] = $this->bundle(2);
        $locked = fopen($final['path'], 'rb'); self::assertTrue(flock($locked, LOCK_EX | LOCK_NB));
        $calls = 0; $started = microtime(true);
        try {
            try {
                (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
                    static function () use (&$calls): void { $calls++; });
                self::fail('A locked source must remain pending.');
            } catch (RuntimeException) {}
        } finally { flock($locked, LOCK_UN); fclose($locked); }
        self::assertSame(0, $calls); self::assertLessThan(2, microtime(true) - $started);
    }

    public function test_linux_normal_shared_group_artifacts_are_accepted_but_public_or_writable_sources_refuse(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) self::markTestSkipped('Native POSIX mode contract.');
        [, $final, $options] = $this->bundle(2);
        clearstatcache(true, $final['path']); self::assertSame(0640, fileperms($final['path']) & 0777);
        $calls = 0;
        (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
            static function () use (&$calls): void { $calls++; });
        self::assertSame(1, $calls);
        foreach ([0644, 0660, 0650] as $mode) {
            self::assertTrue(chmod($final['path'], $mode));
            try {
                (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
                    static function () use (&$calls): void { $calls++; });
                self::fail('Unsafe source permissions must refuse.');
            } catch (RuntimeException) {}
        }
        self::assertSame(1, $calls);
    }

    public function test_native_different_owner_same_private_group_is_supported(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Isolated root fixture changes only its own temporary source UID.');
        }
        [, $final, $options] = $this->bundle(2);
        self::assertTrue(chgrp($final['path'], posix_getegid())); self::assertTrue(chown($final['path'], 33));
        self::assertTrue(chmod($final['path'], 0640)); clearstatcache(true, $final['path']);
        self::assertNotSame(posix_geteuid(), fileowner($final['path']));
        $calls = 0;
        (new SmsRunArtifactPartReader)->oneVerified($this->directory, $final['manifest'], $options, 3, 1,
            static function () use (&$calls): void { $calls++; });
        self::assertSame(1, $calls);
    }
}
