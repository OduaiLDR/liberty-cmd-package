<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Fresh owned temporary directories only. No database, application config or network. */
final class SmsRunArtifactBundleTest extends TestCase
{
    private string $base;
    private string $runDir;
    private SmsRunArtifactBundle $bundles;
    private const ID = '12345678-1234-4234-8234-123456789abc';

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()).'/sms-bundle-test-'.bin2hex(random_bytes(8));
        $this->runDir = $this->base.'/runs/'.self::ID;
        $this->bundles = new SmsRunArtifactBundle;
    }

    protected function tearDown(): void
    {
        // Exactly this test's random directory; never follow links or remove an ancestor.
        self::assertMatchesRegularExpression('~/sms-bundle-test-[a-f0-9]{16}$~D', $this->base);
        if (! is_dir($this->base)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    private function rows(int $count): \Generator
    {
        for ($row = 1; $row <= $count; $row++) yield ['Name'.$row, '14000', '0202555'.sprintf('%03d', $row), '2026-10-26'];
    }

    public function test_explicit_minimum_is_bound_to_artifacts_and_checked_before_publication(): void
    {
        $options = SmsRunOptions::normalize(2, 1, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, 25500);
        $result = $this->bundles->build($this->runDir, 'sms-test', [
            ['First', '26000', '2025550101', '2026-11-02'],
            ['Second', '30000', '2025550102', '2026-11-02'],
        ], 2, $options);
        self::assertSame(2, $result['manifest']['rows']);
        self::assertCount(2, $result['manifest']['parts']);
        self::assertSame($result['path'], $this->bundles->verify($this->runDir, $result['manifest'], $options, 2));
        $changed = array_replace($options, ['minimum_debt_amount' => 31000]);
        $this->expectException(RuntimeException::class);
        $this->bundles->build($this->runDir, 'sms-test', [], 2, $changed);
    }

    public function test_explicit_minimum_rejects_a_low_row_without_publishing_a_file(): void
    {
        $options = SmsRunOptions::normalize(1, 1, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, 25500);
        try {
            $this->bundles->build($this->runDir, 'sms-test', [['First', '25000', '2025550101', '2026-11-02']], 1, $options);
            self::fail('Expected a frozen debt-minimum hold.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('below its frozen minimum', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->runDir.'/v1/published');
    }

    private function build(int $count = 5, int $target = 7, int $cap = 3): array
    {
        return $this->bundles->build($this->runDir, 'sms-test', $this->rows($count), $count, SmsRunOptions::normalize($target, $cap));
    }

    public function test_planned_date_names_are_exact_in_single_downloads_zip_members_and_pure_status_metadata(): void
    {
        foreach ([3, 10] as $cap) {
            $directory = $this->base.'/cap-'.$cap.'/runs/'.self::ID;
            $options = SmsRunOptions::normalize(7, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26');
            $result = $this->bundles->build($directory, 'SMS Export - 2026-10-26', $this->rows(5), 5, $options);
            $parts = $cap === 3 ? ['SMS Export - 2026-10-26 - Part 1 of 2.csv', 'SMS Export - 2026-10-26 - Part 2 of 2.csv']
                : ['SMS Export - 2026-10-26 - Part 1 of 1.csv'];
            self::assertSame($cap === 3 ? 'SMS Export - 2026-10-26.zip' : $parts[0], basename($result['path']));
            self::assertSame($parts, array_column($result['manifest']['parts'], 'name'));
            $this->bundles->validateManifest($result['manifest'], self::ID, $options, 5);
            self::assertSame($result['path'], $this->bundles->verify($directory, $result['manifest'], $options, 5));
            if ($cap === 3) {
                $zip = new ZipArchive; self::assertTrue($zip->open($result['path']));
                try { foreach ($parts as $index => $name) self::assertSame($name, $zip->getNameIndex($index)); }
                finally { $zip->close(); }
            }
            // Publication retries adopt original bytes without consuming a changed iterator.
            $replay = $this->bundles->build($directory, 'SMS Export - 2026-10-26', [], 5, $options);
            self::assertSame($result, $replay);
            $quarantine = $this->bundles->quarantine($directory);
            self::assertNotNull($quarantine); $this->bundles->restore($directory);
            self::assertSame($result['path'], $this->bundles->verify($directory, $result['manifest'], $options, 5));
        }
    }

    public function test_planned_date_names_and_rows_cannot_diverge_from_the_frozen_contract(): void
    {
        $options = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-27');
        $this->refuses(fn () => $this->bundles->build($this->runDir, 'SMS Export - 2026-10-26', $this->rows(5), 5, $options), 'filename');
        $this->refuses(fn () => $this->bundles->build($this->runDir, 'SMS Export - 2026-10-27', $this->rows(5), 5, $options), 'row date');
        $this->refuses(fn () => $this->bundles->build($this->runDir, 'SMS Export - 2026-10-26', $this->rows(5), 5, SmsRunOptions::normalize(7, 3)), 'filename');
    }

    public function test_opted_in_drop_range_names_freeze_in_single_downloads_zip_members_and_recovery(): void
    {
        foreach ([3, 10] as $cap) {
            $directory = $this->base.'/range-'.$cap.'/runs/'.self::ID;
            $options = SmsRunOptions::normalize(7, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::DROP_RANGE_FILENAME_VERSION);
            $stem = 'SMS Export - 2026-10-26 - SMS9999-SMS10000';
            $result = $this->bundles->build($directory, $stem, $this->rows(5), 5, $options);
            $count = $cap === 3 ? 2 : 1;
            self::assertSame($cap === 3 ? $stem.'.zip' : $stem.' - Part 1 of 1.csv', basename($result['path']));
            self::assertSame($stem, $this->bundles->publishedStem($directory, $options, 5));
            self::assertTrue(SmsRunArtifactBundle::isPlannedDownloadName(basename($result['path'])));
            self::assertTrue(SmsRunArtifactBundle::isSafeArchiveKey('private/sms/'.$result['manifest']['download']['name']));
            foreach ($result['manifest']['parts'] as $index => $part) self::assertSame($stem.' - Part '.($index + 1).' of '.$count.'.csv', $part['name']);
            self::assertSame($result, $this->bundles->build($directory, $stem, [], 5, $options));
            $this->refuses(fn () => $this->bundles->build($directory, 'SMS Export - 2026-10-26 - SMS0001-SMS0002', [], 5, $options), 'different filename');
            $this->refuses(fn () => $this->bundles->validateManifest($result['manifest'], self::ID,
                SmsRunOptions::normalize(7, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26'), 5));
        }
    }

    public function test_safe_new_archive_names_only_allow_exact_patterns_not_arbitrary_spaces_or_path_injection(): void
    {
        foreach (['SMS Export - 2026-10-26.zip', 'SMS Export - 2026-10-26 - Part 1 of 1.csv'] as $name) {
            self::assertTrue(SmsRunArtifactBundle::isPlannedDownloadName($name));
            self::assertTrue(SmsRunArtifactBundle::isSafeArchiveKey('private/sms/id/v1/'.$name));
        }
        foreach (['SMS Export - 2026-02-29.zip', 'SMS Export - 2026-10-26 - Part 2 of 2.csv', 'SMS Export - 2026-10-26.csv',
            '../SMS Export - 2026-10-26.zip', 'some file.zip', "SMS Export - 2026-10-26.zip\n"] as $name) {
            self::assertFalse(SmsRunArtifactBundle::isPlannedDownloadName($name));
        }
        self::assertFalse(SmsRunArtifactBundle::isSafeArchiveKey('private space/SMS Export - 2026-10-26.zip'));
    }

    private function refuses(callable $operation, string $message = ''): void
    {
        try { $operation(); self::fail('Expected safe artifact refusal.'); }
        catch (RuntimeException $error) { if ($message !== '') self::assertStringContainsString($message, $error->getMessage()); }
    }

    private function csvRows(string $text): array
    {
        $stream = fopen('php://memory', 'w+');
        try {
            fwrite($stream, $text); rewind($stream); $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) $rows[] = $row;
            return $rows;
        } finally { fclose($stream); }
    }

    public function test_shortfall_splits_actual_selected_records_three_plus_two_without_raw_part_leaks(): void
    {
        $result = $this->build(); $manifest = $result['manifest'];
        self::assertSame('zip', $manifest['download']['format']);
        self::assertSame([3,2], array_column($manifest['parts'], 'rows'));
        self::assertSame(5, $manifest['rows']);
        self::assertSame(['manifest.json','sms-test.zip'], array_values(array_diff(scandir(dirname($result['path'])), ['.','..'])));
        self::assertSame($result['bytes'], filesize($result['path']));
        self::assertSame($result['sha256'], hash_file('sha256', $result['path']));
        self::assertSame($result['path'], $this->bundles->verify($this->runDir, $manifest, SmsRunOptions::normalize(7,3), 5));
        $zip = new ZipArchive; self::assertTrue($zip->open($result['path']));
        try {
            self::assertSame(2, $zip->numFiles); $all = [];
            foreach ($manifest['parts'] as $index => $part) {
                self::assertSame($part['name'], $zip->getNameIndex($index));
                $text = $zip->getFromIndex($index);
                self::assertSame($part['bytes'], strlen($text)); self::assertSame($part['sha256'], hash('sha256', $text));
                self::assertStringContainsString("\r\n", $text);
                $rows = $this->csvRows($text);
                self::assertSame(['First name','debt load','phone1','send date'], array_shift($rows));
                self::assertCount($part['rows'], $rows); $all = [...$all, ...$rows];
            }
            self::assertSame(iterator_to_array($this->rows(5)), $all);
        } finally { $zip->close(); }
    }

    public function test_exact_multiple_has_no_empty_tail_and_archive_bytes_are_deterministic(): void
    {
        $first = $this->build(6, 6, 3);
        $other = $this->bundles->build($this->base.'/another/runs/'.self::ID, 'sms-test', $this->rows(6), 6, SmsRunOptions::normalize(6,3));
        self::assertSame([3,3], array_column($first['manifest']['parts'], 'rows'));
        self::assertSame($first['sha256'], $other['sha256']);
        self::assertSame(file_get_contents($first['path']), file_get_contents($other['path']));
    }

    public function test_one_csv_preserves_formula_safety_leading_zero_and_embedded_csv_fields(): void
    {
        $rows = [['=FORMULA', '14000', '0123456789', '2026-10-26'], ["Ann,\nExample", '1000', '0000000000', '+not-a-formula']];
        $result = $this->bundles->build($this->runDir, 'single', $rows, 2, SmsRunOptions::normalize(7,10));
        self::assertSame('csv', $result['manifest']['download']['format']);
        self::assertSame([2], array_column($result['manifest']['parts'], 'rows'));
        self::assertSame(['First name','debt load','phone1','send date'], $this->csvRows(file_get_contents($result['path']))[0]);
        self::assertSame([["'=FORMULA", '14000', '0123456789', '2026-10-26'], ["Ann,\nExample", '1000', '0000000000', "'+not-a-formula"]], array_slice($this->csvRows(file_get_contents($result['path'])),1));
        self::assertSame(['manifest.json','single.csv'], array_values(array_diff(scandir(dirname($result['path'])), ['.','..'])));
    }

    public function test_preflight_refuses_zero_excessive_parts_and_unsupported_format_before_io_or_iteration(): void
    {
        $consumed = false;
        $cells = (function () use (&$consumed) { $consumed = true; yield ['Name','1000','0123456789','2026-10-26']; })();
        foreach ([[0,SmsRunOptions::normalize(7,3)], [8,SmsRunOptions::normalize(7,3)], [1001,SmsRunOptions::normalize(1001,1)],
            [1,array_replace(SmsRunOptions::normalize(7,3), ['csv_format' => 'legacy-nine-column-v1'])]] as [$expected,$options]) {
            $this->refuses(fn () => $this->bundles->build($this->runDir, 'test', $cells, $expected, $options));
        }
        self::assertFalse($consumed); self::assertDirectoryDoesNotExist($this->base);
    }

    public function test_mismatched_counts_and_invalid_cells_never_publish_and_scratch_is_quarantinable(): void
    {
        foreach ([$this->rows(1), $this->rows(3), [['Only','three','cells']], [['Name', 1000, '0123456789','2026-10-26']]] as $cells) {
            $this->refuses(fn () => $this->bundles->build($this->runDir, 'test', $cells, 2, SmsRunOptions::normalize(2,1)));
            self::assertDirectoryDoesNotExist($this->runDir.'/v1/published');
        }
        $quarantine = $this->bundles->quarantine($this->runDir);
        self::assertSame($this->runDir.'/v1.delete-pending', $quarantine);
        self::assertTrue($this->bundles->cleanup($this->runDir));
        self::assertDirectoryDoesNotExist($quarantine);
    }

    public function test_matching_published_retry_adopts_without_consuming_cells_but_changed_intent_is_refused(): void
    {
        $result = $this->build();
        $never = (function () { throw new RuntimeException('The already-published retry consumed rows.'); yield []; })();
        self::assertSame($result, $this->bundles->build($this->runDir, 'sms-test', $never, 5, SmsRunOptions::normalize(7,3)));
        $this->refuses(fn () => $this->bundles->build($this->runDir, 'different', $never, 5, SmsRunOptions::normalize(7,3)), 'different filename');
        $this->refuses(fn () => $this->bundles->build($this->runDir, 'sms-test', $never, 5, SmsRunOptions::normalize(8,3)), 'frozen run options');
        $this->refuses(fn () => $this->bundles->verify($this->runDir, $result['manifest'], SmsRunOptions::normalize(7,2), 5));
        self::assertSame($result['sha256'], hash_file('sha256', $result['path']));
    }

    public function test_changed_database_or_disk_manifest_and_same_size_payload_corruption_are_refused(): void
    {
        $result = $this->build(); $options = SmsRunOptions::normalize(7,3);
        $changed = $result['manifest']; $changed['parts'][0]['rows'] = 2;
        $this->refuses(fn () => $this->bundles->verify($this->runDir, $changed, $options, 5));
        $receipt = dirname($result['path']).'/manifest.json';
        $original = file_get_contents($receipt);
        $changed = $result['manifest']; $changed['options_sha256'] = str_repeat('a',64);
        file_put_contents($receipt, json_encode($changed, JSON_THROW_ON_ERROR));
        $this->refuses(fn () => $this->bundles->verify($this->runDir, $result['manifest'], $options,5));
        file_put_contents($receipt,$original);
        file_put_contents($result['path'],str_repeat('x',$result['bytes']));
        $this->refuses(fn () => $this->bundles->verify($this->runDir,$result['manifest'],$options,5),'bytes/hash');
    }

    public function test_manifest_identity_names_format_and_part_hashes_cannot_be_reinterpreted(): void
    {
        $result = $this->build(); $receipt = dirname($result['path']).'/manifest.json';
        $original = file_get_contents($receipt);
        $changes = [
            static function (array $m): array { $m['request_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; return $m; },
            static function (array $m): array { $m['download']['name'] = '../escape.zip'; return $m; },
            static function (array $m): array { $m['csv_format'] = 'legacy-nine-column-v1'; return $m; },
            static function (array $m): array { $m['parts'][0]['name'] = 'different.csv'; return $m; },
            static function (array $m): array { $m['parts'][0]['sha256'] = str_repeat('a',64); return $m; },
            static function (array $m): array { $m['parts'][0]['bytes']++; return $m; },
        ];
        foreach ($changes as $change) {
            $changed = $change($result['manifest']);
            // Even changing both the disk and supplied receipt cannot bypass the
            // identity/name/schema rules or the hashes of actual ZIP members.
            file_put_contents($receipt,json_encode($changed,JSON_THROW_ON_ERROR));
            $this->refuses(fn () => $this->bundles->verify($this->runDir,$changed,SmsRunOptions::normalize(7,3),5));
            file_put_contents($receipt,$original);
        }
        self::assertSame($result['path'],$this->bundles->verify($this->runDir,$result['manifest'],SmsRunOptions::normalize(7,3),5));
    }

    public function test_shallow_http_verification_pins_primary_bytes_without_reparsing_members(): void
    {
        $result = $this->build(); $options = SmsRunOptions::normalize(7,3);
        self::assertSame($result['path'],$this->bundles->verify($this->runDir,$result['manifest'],$options,5,false));
        // A structurally valid receipt with a deliberately wrong member hash
        // distinguishes shallow verification from deep member inspection. HTTP
        // uses its trusted, background-validated DB receipt, never an arbitrary one.
        $memberReceipt = $result['manifest']; $memberReceipt['parts'][0]['sha256'] = str_repeat('a',64);
        $receiptPath = dirname($result['path']).'/manifest.json'; $original = file_get_contents($receiptPath);
        file_put_contents($receiptPath,json_encode($memberReceipt,JSON_THROW_ON_ERROR));
        self::assertSame($result['path'],$this->bundles->verify($this->runDir,$memberReceipt,$options,5,false));
        $this->refuses(fn () => $this->bundles->verify($this->runDir,$memberReceipt,$options,5,true),'part hash');
        file_put_contents($receiptPath,$original);
        file_put_contents($result['path'],str_repeat('x',$result['bytes']));
        $this->refuses(fn () => $this->bundles->verify($this->runDir,$result['manifest'],$options,5,false),'bytes/hash');
    }

    public function test_bundle_quarantine_restore_and_cleanup_cover_receipt_and_primary_together(): void
    {
        $result = $this->build();
        $quarantine = $this->bundles->quarantine($this->runDir);
        self::assertFileDoesNotExist($result['path']);
        self::assertFileExists($quarantine.'/published/manifest.json');
        self::assertFileExists($quarantine.'/published/sms-test.zip');
        self::assertSame($quarantine,$this->bundles->quarantine($this->runDir));
        $this->bundles->restore($this->runDir);
        self::assertSame($result['path'],$this->bundles->verify($this->runDir,$result['manifest'],SmsRunOptions::normalize(7,3),5));
        $this->bundles->quarantine($this->runDir);
        self::assertTrue($this->bundles->cleanup($this->runDir));
        self::assertTrue($this->bundles->cleanup($this->runDir));
        self::assertNull($this->bundles->quarantine($this->runDir));
    }

    public function test_quarantine_and_cleanup_refuse_foreign_entries_without_deleting_other_files(): void
    {
        $result = $this->build(); $foreign=$this->runDir.'/v1/foreign.txt';
        file_put_contents($foreign,'user evidence');
        $this->refuses(fn () => $this->bundles->quarantine($this->runDir),'Unexpected');
        self::assertFileExists($result['path']); self::assertSame('user evidence',file_get_contents($foreign));
        unlink($foreign); $q=$this->bundles->quarantine($this->runDir);
        file_put_contents($q.'/published/foreign.txt','user evidence');
        $this->refuses(fn () => $this->bundles->cleanup($this->runDir),'Unexpected');
        self::assertFileExists($q.'/published/sms-test.zip'); self::assertFileExists($q.'/published/manifest.json');
    }

    public function test_cleanup_can_resume_after_data_was_removed_but_receipt_was_retained(): void
    {
        $result=$this->build(); $q=$this->bundles->quarantine($this->runDir);
        unlink($q.'/published/'.basename($result['path']));
        self::assertTrue($this->bundles->cleanup($this->runDir));
        self::assertDirectoryDoesNotExist($q);
    }

    public function test_conflicting_quarantine_and_invalid_paths_do_not_move_or_remove_evidence(): void
    {
        $result=$this->build(); mkdir($this->runDir.'/v1.delete-pending');
        foreach ([fn ()=>$this->bundles->quarantine($this->runDir), fn ()=>$this->bundles->restore($this->runDir), fn ()=>$this->bundles->cleanup($this->runDir)] as $operation) $this->refuses($operation,'Conflicting');
        foreach (['/', 'relative/runs/'.self::ID, $this->base.'/runs/not-a-uuid', $this->base.'/../runs/'.self::ID, $this->runDir.'/v1'] as $path) {
            $this->refuses(fn ()=>$this->bundles->cleanup($path),'absolute');
        }
        self::assertFileExists($result['path']);
    }

    public function test_symlinks_are_never_followed_when_platform_allows_creating_them(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('Windows test symlink creation requires a separately granted privilege.');
        $result=$this->build(); $target=$this->base.'/foreign-evidence'; mkdir($target);
        $link=$this->runDir.'/v1/staging-1234567890abcdef';
        if (! @symlink($target,$link)) self::markTestSkipped('The local platform does not permit test symlinks.');
        $this->refuses(fn ()=>$this->bundles->quarantine($this->runDir),'Unexpected');
        self::assertDirectoryExists($target); self::assertFileExists($result['path']);
    }

    public function test_receipt_binds_explicit_selector_and_subset_without_upgrading_old_options(): void
    {
        $options = SmsRunOptions::normalize(7, 3, ['T2','T9'], SmsRunOptions::TIER_SELECTION_POLICY);
        $result = $this->bundles->build($this->runDir, 'marked', $this->rows(5), 5, $options);
        self::assertSame($result['path'], $this->bundles->verify($this->runDir, $result['manifest'], $options, 5));
        $oldShape = $options; unset($oldShape['tier_selection_policy']);
        foreach ([$oldShape, SmsRunOptions::normalize(7, 3, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY)] as $changed) {
            $this->refuses(fn () => $this->bundles->verify($this->runDir, $result['manifest'], $changed, 5));
        }
        self::assertSame($result['sha256'], hash_file('sha256', $result['path']));
    }
}
