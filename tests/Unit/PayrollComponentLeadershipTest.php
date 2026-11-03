<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\Formatter as NsfFormatter;
use Cmd\Reports\Services\NsfReportReadiness;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/** Real saved workbook readers and existing formula parity; no database or source report command. */
final class PayrollComponentLeadershipTest extends TestCase
{
    private const PERIOD = '2026-10-01';
    private string $directory;
    private PayrollReportArtifactArchive $archive;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cs-leadership-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->archive = new PayrollReportArtifactArchive($this->directory . '/archive');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else { chmod($file->getPathname(), 0600); unlink($file->getPathname()); } }
        rmdir($this->directory);
    }

    private function retention(bool $cap = false): array
    {
        $artifacts = [];
        foreach (['ldr' => 1000, 'plaw' => 2000] as $source => $base) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Retention Data');
            $sheet->fromArray([['ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results', 'Enrolled Debt', 'Cleared Payments', 'Reconsideration Date', 'Dropped Date', 'Retention Payment Date', 'T1 Rate', 'T2 Rate', 'T3 Rate', 'T4 Rate', 'Cancel Request Date']], null, 'A1');
            $rows = $cap ? 150 : 4;
            for ($i = 1; $i <= $rows; $i++) $sheet->fromArray([[$base + $i, 'Synthetic Client', 'Frozen Agent', '2026-10-03',
                $cap || $i <= 2 ? 'RETAINED' : 'DROPPED', $cap ? 70000 : 10000, 1, '2026-10-02', null, '2026-10-04', 2, 5, 10, 40, '2026-10-01']], null, 'A' . ($i + 1));
            $path = $this->directory . '/retention_' . $source . '.xlsx';
            (new Xlsx($book))->save($path); $book->disconnectWorksheets();
            $artifacts['retention_' . $source] = $this->archive->capture('retention_' . $source, self::PERIOD, str_repeat('a', 32),
                ['path' => $path, 'filename' => basename($path)], ['fixtureBasis' => 'legacy_without_tranches']);
        }
        return $artifacts;
    }

    private function nsf(): array
    {
        $previous = Container::getInstance();
        $app = new Application($this->directory); $app->useStoragePath($this->directory . '/storage');
        mkdir($this->directory . '/storage/app', 0700, true);
        try {
            $artifacts = [];
            foreach (['ldr' => 3000, 'plaw' => 4000] as $source => $base) {
                $rows = [];
                for ($i = 1; $i <= 250; $i++) $rows[] = ['ID' => $base + $i, 'AGENT' => 'Frozen NSF Agent', 'NSF_RETURNED_DATE' => '2026-10-01',
                    'NSF_ACTION' => 'Resubmitted', 'NSF_RECOUP_DATE' => '2026-10-02', 'CLEARED_DATE' => '2026-10-03', 'valid_commission' => true];
                $rows[] = ['ID' => $base + 900, 'AGENT' => 'OTHERS', 'NSF_RETURNED_DATE' => '2026-10-01', 'NSF_ACTION' => 'Resubmitted',
                    'NSF_RECOUP_DATE' => '2026-10-02', 'CLEARED_DATE' => '2026-10-03', 'valid_commission' => true];
                $file = (new NsfFormatter)->buildWorkbook($rows, [['agent' => 'Frozen NSF Agent', 'assignments' => 250, 'actions' => 250,
                    'ratio' => 1, 'actions_tier' => 3, 'cleared_tier' => 3, 'rate' => 4, 'clears' => 250, 'commission' => 1000, 'location' => 'Jordan', 'company' => $source]],
                    $source, self::PERIOD, '2026-10-31', null, $source, [],
                    NsfReportReadiness::timing(self::PERIOD, new \DateTimeImmutable('2026-11-03T20:00:00Z')) + ['runId' => str_repeat($source === 'ldr' ? 'c' : 'd', 32)]);
                $artifacts['nsf_' . $source] = $this->archive->capture('nsf_' . $source, self::PERIOD, str_repeat('b', 32), $file, ['fixtureBasis' => 'frozen_nsf']);
            }
            return $artifacts;
        } finally { Container::setInstance($previous); }
    }

    private function privateCall(object $object, string $method, mixed ...$args): mixed
    {
        $object->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    public function test_original_retention_supports_nick_and_rama_base_without_querying_missing_tranches(): void
    {
        $inputs = $this->retention();
        $before = $this->files();
        $command = new GenerateRetentionManagerCommission;
        $result = $command->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
        $nick = $result['reports']['retention_team_leader']; $rama = $result['reports']['retention_manager'];
        $this->assertSame('ready', $nick['state']); $this->assertSame(32.0, $nick['knownSubtotal']);
        $this->assertSame('partial', $rama['state']); $this->assertSame(1000.0, $rama['knownSubtotal']);
        $this->assertNull($rama['snapshot']['summary']['bonusCommission']);
        $this->assertNull($rama['snapshot']['summary']['totalPay']);
        $this->assertSame('rama_bonus', $rama['pendingInputs'][0]['code']);
        $this->assertSame('pending', $result['reports']['nsf_team_leader']['state']);
        $this->assertNull($result['reports']['nsf_team_leader']['knownSubtotal']);
        $this->assertNull($result['reports']['nsf_team_leader']['snapshot']);
        // Existing private calculation is the independent compatibility boundary.
        $rows = [];
        foreach (['ldr' => 'LDR', 'plaw' => 'Progress Law'] as $source => $display) array_push($rows, ...$this->privateCall($command, 'loadRetentionReportRowsFromXlsx', $inputs['retention_' . $source]['path'], $display));
        $rows = $this->privateCall($command, 'filterRetentionRowsForPeriod', $rows, self::PERIOD, '2026-10-31');
        $this->assertSame($this->privateCall($command, 'buildNickPayrollSnapshot', $rows, self::PERIOD, '2026-10-31'), $nick['snapshot']);
        $this->assertSame($before, $this->files());
        $this->assertSame($result, $command->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs));
    }

    public function test_real_nsf_formatter_adds_anthony_without_changing_retention_roles_or_using_placeholder_rows(): void
    {
        $inputs = $this->retention(); $nsf = $this->nsf(); $command = new GenerateRetentionManagerCommission;
        $before = $command->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
        $files = $this->files();
        $after = $command->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs, $nsf);
        $this->assertSame($before['reports']['retention_manager'], $after['reports']['retention_manager']);
        $this->assertSame($before['reports']['retention_team_leader'], $after['reports']['retention_team_leader']);
        $anthony = $after['reports']['nsf_team_leader'];
        $this->assertSame('ready', $anthony['state']); $this->assertSame(350.0, $anthony['knownSubtotal']);
        $this->assertSame(500, $anthony['snapshot']['summary']['clearCount']);
        $this->assertCount(1, $anthony['snapshot']['team']);
        $this->assertSame('Frozen NSF Agent', $anthony['snapshot']['team'][0]['employeeName']);
        $this->assertCount(2, $anthony['snapshot']['nsfReadinessRunIds']);
        $this->assertSame($files, $this->files());
    }

    public function test_nick_cap_matches_existing_formula(): void
    {
        $built = (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $this->retention(true));
        $this->assertSame(2000.0, $built['reports']['retention_team_leader']['knownSubtotal']);
    }

    public function test_wrong_period_or_incomplete_source_pair_cannot_become_zero_commission(): void
    {
        $inputs = $this->retention();
        try { (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents('2026-09-01', $inputs); $this->fail('Wrong source month accepted.'); }
        catch (\RuntimeException) { $this->assertTrue(true); }
        unset($inputs['retention_plaw']);
        $this->expectException(\InvalidArgumentException::class);
        (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
    }

    public function test_changed_source_is_rejected_without_live_recovery(): void
    {
        $inputs = $this->retention(); $path = $inputs['retention_ldr']['path'];
        chmod($path, 0600); file_put_contents($path, 'changed');
        $this->expectException(\RuntimeException::class);
        (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
    }

    public static function missingInputChanges(): array { return [['F1', 'Unknown Column'], ['F2', null]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingInputChanges')]
    public function test_missing_required_debt_column_or_retained_debt_value_is_not_a_zero_amount(string $cell, mixed $value): void
    {
        $inputs = $this->retention(); $path = $inputs['retention_ldr']['path'];
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $book->getSheetByName('Retention Data')->setCellValue($cell, $value);
        chmod($path, 0600); (new Xlsx($book))->save($path); $book->disconnectWorksheets();
        $inputs['retention_ldr']['sha256'] = hash_file('sha256', $path);
        $this->expectException(\RuntimeException::class);
        (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
    }

    public function test_foreign_or_incomplete_nsf_artifacts_do_not_create_anthony_zero(): void
    {
        $inputs = $this->retention(); $nsf = $this->nsf();
        unset($nsf['nsf_plaw']);
        $this->expectException(\InvalidArgumentException::class);
        (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs, $nsf);
    }

    public function test_an_out_of_period_retained_row_with_missing_debt_does_not_block_this_month(): void
    {
        $inputs = $this->retention(); $path = $inputs['retention_ldr']['path'];
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $book->getSheetByName('Retention Data');
        $sheet->setCellValue('F2', null); $sheet->setCellValue('O2', '2026-09-01');
        chmod($path, 0600); (new Xlsx($book))->save($path); $book->disconnectWorksheets();
        $inputs['retention_ldr']['sha256'] = hash_file('sha256', $path);
        $built = (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipComponents(self::PERIOD, $inputs);
        $this->assertSame(7, $built['reports']['retention_team_leader']['snapshot']['summary']['assignedCount']);
        $this->assertSame(12.0, $built['reports']['retention_team_leader']['knownSubtotal']);
        $this->assertSame(750.0, $built['reports']['retention_manager']['knownSubtotal']);
    }

    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files); return $files;
    }
}
