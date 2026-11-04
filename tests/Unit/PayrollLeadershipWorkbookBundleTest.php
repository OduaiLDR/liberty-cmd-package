<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PayrollLeadershipWorkbookBundleTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { parent::setUp(); $this->directory = sys_get_temp_dir() . '/payroll-leader-test-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700); }
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
        parent::tearDown();
    }
    private function sources(): array
    {
        $artifacts = [];
        foreach (['ldr' => 101, 'plaw' => 102] as $source => $id) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle('Retention Data')->fromArray([
                ['ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results', 'Enrolled Debt', 'Cleared Payments', 'Retention Payment Date', 'Cancel Request Date'],
                [$id, 'Synthetic Client', 'Synthetic Retention Agent', '2098-09-05', $source === 'ldr' ? 'RETAINED' : 'DROPPED', 10000, 2, '2098-09-10', '2098-09-01'],
            ], null, 'A1');
            $path = $this->directory . '/retention_' . $source . '.xlsx';
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $artifacts['retention_' . $source] = ['path' => $path, 'context' => ['trancheContextReady' => true,
                'trancheByClient' => ['LLG-' . $id => ['TRANCHE' => 'Frozen Tranche', 'CUT_OFF' => '2098-11-01', 'MADE_CUT_OFF' => true, 'BONUS_FLAG' => $source === 'ldr' ? 'Bonus' : '']]]];
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle('NSF Data')->fromArray([
                ['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission'],
                [$id, 'Archived NSF Agent', '2098-09-01', 'Resubmitted', '2098-09-03', '2098-09-04', 1],
                [$id + 1000, 'Excluded Unassigned Agent', '2098-09-01', 'Resubmitted', '2098-09-03', '2098-09-04', 1],
            ], null, 'A1');
            $book->createSheet()->setTitle('Agent Summary')->fromArray([['NSF Agent'], ['Archived NSF Agent'], ['Zero Activity Agent'], [''], ['Unassigned Agents'], ['Excluded Unassigned Agent']], null, 'A1');
            $path = $this->directory . '/nsf_' . $source . '.xlsx';
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $artifacts['nsf_' . $source] = ['path' => $path];
        }
        return $artifacts;
    }
    public function test_real_workbooks_and_json_use_the_same_archived_data_and_frozen_roster_without_cloud_lookup(): void
    {
        $command = new GenerateRetentionManagerCommission;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $built = $command->buildPayrollReviewWorkbookBundle('2098-09-01', '2098-09-30', $this->sources(), $this->directory . '/output');
        $this->assertCount(3, $built['files']);
        foreach (['retention_manager', 'retention_team_leader', 'nsf_team_leader'] as $kind) $this->assertSame($kind, $built['reports'][$kind]['reportKind']);
        $this->assertSame(2000.0, $built['reports']['retention_manager']['summary']['totalPay']);
        $this->assertSame(8.0, $built['reports']['retention_team_leader']['summary']['totalPay']);
        $this->assertSame(['Archived NSF Agent', 'Zero Activity Agent'], array_column($built['reports']['nsf_team_leader']['team'], 'employeeName'));
        $this->assertSame(0.0, $built['reports']['nsf_team_leader']['summary']['totalPay']);
        foreach ($built['files'] as $key => $file) {
            $this->assertFileExists($file['path']);
            $this->assertStringStartsWith($this->directory . '/output/', $file['path']);
            $book = IOFactory::load($file['path']);
            $summary = $book->getSheetByName('Commission Summary');
            $this->assertNotNull($summary);
            if ($key === 'customer_support_manager') $this->assertSame(2000, $summary->getCell('B15')->getValue());
            if ($key === 'retention_team_leader') $this->assertSame(8.0, $summary->getCell('B8')->getValue());
            $book->disconnectWorksheets();
        }
    }
    public function test_missing_frozen_context_cannot_fall_back_to_current_azure_data(): void
    {
        $artifacts = $this->sources();
        unset($artifacts['retention_ldr']['context']);
        $this->expectException(\RuntimeException::class);
        (new GenerateRetentionManagerCommission)->buildPayrollReviewWorkbookBundle('2098-09-01', '2098-09-30', $artifacts, $this->directory . '/output');
    }

    private function command(): GenerateRetentionManagerCommission
    {
        $command = new GenerateRetentionManagerCommission;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        return $command;
    }

    private function changeWorkbook(string $path, callable $change): void
    {
        $book = IOFactory::load($path);
        try { $change($book); (new Xlsx($book))->save($path); }
        finally { $book->disconnectWorksheets(); }
    }

    public function test_nick_uses_only_retention_files_without_rama_context_or_nsf(): void
    {
        $sources = $this->sources();
        $sources = array_intersect_key($sources, array_flip(['retention_ldr', 'retention_plaw']));
        foreach ($sources as &$artifact) unset($artifact['context']);
        unset($artifact);
        $result = $this->command()->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', $sources);
        $this->assertSame('Nick Jones', $result['managerName']);
        $this->assertSame('2098-09-01', $result['periodStart']);
        $this->assertSame('2098-09-30', $result['periodEnd']);
        $this->assertSame(8.0, $result['summary']['totalPay']);
        $this->assertSame(2, $result['summary']['assignedCount']);
        $this->assertDirectoryDoesNotExist($this->directory . '/output');
    }

    public function test_independent_snapshots_need_no_console_output_or_laravel_facade(): void
    {
        $sources = $this->sources();
        $previous = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        try {
            foreach (['retention_team_leader', 'nsf_team_leader'] as $kind) {
                $command = new GenerateRetentionManagerCommission;
                $snapshot = $command->buildPayrollReviewLeadershipSnapshot($kind, '2098-09-01', '2098-09-30', $sources);
                $this->assertSame($kind, $snapshot['reportKind']);
                $this->assertNull((new \ReflectionProperty($command, 'output'))->getValue($command));
            }
        } finally { \Illuminate\Support\Facades\Facade::setFacadeApplication($previous); }
    }

    public function test_independent_snapshot_restores_console_output_on_failure(): void
    {
        $command = $this->command();
        $output = new \ReflectionProperty($command, 'output');
        $previous = $output->getValue($command);
        try {
            $command->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', []);
            $this->fail('Missing saved sources must be rejected');
        } catch (\RuntimeException) {
            $this->assertSame($previous, $output->getValue($command));
        }
    }

    public function test_rama_requires_its_frozen_context_but_not_nsf(): void
    {
        $sources = array_intersect_key($this->sources(), array_flip(['retention_ldr', 'retention_plaw']));
        $this->assertSame(2000.0, $this->command()->buildPayrollReviewLeadershipSnapshot('retention_manager', '2098-09-01', '2098-09-30', $sources)['summary']['totalPay']);
        unset($sources['retention_plaw']['context']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tranche/cutoff');
        $this->command()->buildPayrollReviewLeadershipSnapshot('retention_manager', '2098-09-01', '2098-09-30', $sources);
    }

    public function test_anthony_uses_archived_roster_and_frozen_validity_without_retention_or_current_roster(): void
    {
        $sources = array_intersect_key($this->sources(), array_flip(['nsf_ldr', 'nsf_plaw']));
        foreach ($sources as $artifact) $this->changeWorkbook($artifact['path'], static function ($book): void {
            // The saved valid flag remains authoritative even if these dates would not recalculate as valid.
            $book->getSheetByName('NSF Data')->setCellValue('F2', null);
        });
        $command = $this->command();
        $cache = new \ReflectionProperty($command, 'anthonyRosterCache');
        $cache->setValue($command, ['Different Current Agent']);
        $result = $command->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
        $this->assertSame(['Archived NSF Agent', 'Zero Activity Agent'], array_column($result['team'], 'employeeName'));
        $this->assertSame(2, $result['summary']['assignedCount']);
        $this->assertSame(2, $result['summary']['clearCount']);
        $this->assertSame(['Different Current Agent'], $cache->getValue($command));
    }

    public function test_nick_preserves_month_filter_and_cross_company_contact_deduplication(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['retention_plaw']['path'], static function ($book): void {
            $sheet = $book->getSheetByName('Retention Data');
            $sheet->setCellValue('A2', 101);
            $sheet->setCellValue('I2', '2098-09-02');
            $sheet->fromArray([[103, 'Outside Month', 'Synthetic Agent', '2098-08-01', 'RETAINED', 10000, 2, '2098-09-10', '2098-08-31']], null, 'A3');
        });
        $result = $this->command()->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', $sources);
        $this->assertSame(1, $result['summary']['assignedCount']);
        $this->assertSame(1, $result['summary']['retainedCount']);
        $this->assertSame(8.0, $result['summary']['totalPay']);
    }

    public function test_nick_breakdown_uses_the_same_selected_accounts_when_payment_is_outside_month_or_blank(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['retention_ldr']['path'], static function ($book): void {
            $sheet = $book->getSheetByName('Retention Data');
            $sheet->setCellValue('H2', '2098-10-01');
            $sheet->fromArray([[103, 'Second Retained Client', 'Other Retention Agent', '2098-09-03', 'RETAINED', 35000, 2, null, '2098-09-05']], null, 'A3');
        });
        $sources['retention_ldr']['context']['trancheByClient']['LLG-103'] = [
            'TRANCHE' => 'Frozen Tranche', 'CUT_OFF' => '2098-11-01', 'MADE_CUT_OFF' => true, 'BONUS_FLAG' => 'Bonus',
        ];
        $independent = $this->command()->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', $sources);
        $bundle = $this->command()->buildPayrollReviewWorkbookBundle('2098-09-01', '2098-09-30', $sources, $this->directory . '/output');
        foreach ([$independent, $bundle['reports']['retention_team_leader']] as $snapshot) {
            $this->assertSame(3, $snapshot['summary']['assignedCount']);
            $this->assertSame(2, $snapshot['summary']['retainedCount']);
            $this->assertSame(18.0, $snapshot['summary']['totalPay']);
            $this->assertSame(18.0, $snapshot['summary']['uncappedCommission']);
            $this->assertSame(0.0, $snapshot['summary']['capReduction']);
            $this->assertArrayNotHasKey('calculationNote', $snapshot);
            $lineCents = array_map(static fn ($line) => (int) round($line['commissionTotal'] * 100), $snapshot['lines']);
            $this->assertSame(1800, array_sum($lineCents));
            $dropped = array_values(array_filter($snapshot['lines'], static fn ($line) => $line['source'] === 'Progress Law'));
            $this->assertSame(0.0, $dropped[0]['commissionTotal']);
        }
    }

    public function test_nick_cap_is_explained_without_prorating_team_rows_or_changing_pay(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['retention_ldr']['path'], static function ($book): void {
            $rows = [];
            for ($id = 1; $id <= 300; $id++) $rows[] = [$id, 'Synthetic Retained Client', 'Synthetic Retention Agent', '2098-09-01', 'RETAINED', 10000, 2, null, '2098-09-01'];
            $book->getSheet(0)->fromArray($rows, null, 'A2');
        });
        $snapshot = $this->command()->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', $sources);
        $this->assertSame(2400.0, $snapshot['summary']['uncappedCommission']);
        $this->assertSame(400.0, $snapshot['summary']['capReduction']);
        $this->assertSame(2000.0, $snapshot['summary']['commissionCap']);
        $this->assertSame(2000.0, $snapshot['summary']['totalPay']);
        $this->assertSame(2400.0, array_sum(array_column($snapshot['lines'], 'commissionTotal')));
        $this->assertSame($snapshot['summary']['totalPay'], $snapshot['summary']['uncappedCommission'] - $snapshot['summary']['capReduction']);
        $this->assertSame('Team rows show uncapped commission. The existing monthly cap reduces the report total.', $snapshot['calculationNote']);
    }

    public function test_genuine_empty_sources_with_complete_headers_produce_zero_without_fallback(): void
    {
        $sources = $this->sources();
        foreach ($sources as $artifact) $this->changeWorkbook($artifact['path'], static function ($book): void {
            $sheet = $book->getSheet(0);
            $sheet->removeRow(2, max(1, $sheet->getHighestDataRow() - 1));
        });
        foreach (['retention_team_leader', 'nsf_team_leader'] as $kind) {
            $result = $this->command()->buildPayrollReviewLeadershipSnapshot($kind, '2098-09-01', '2098-09-30', $sources);
            $this->assertSame(0, $result['summary']['assignedCount']);
            $this->assertSame(0.0, $result['summary']['totalPay']);
        }
    }

    public static function invalidArchivedDetail(): array
    {
        return [
            ['retention_team_leader', 'retention_ldr', 'F1', 'Unknown Debt'],
            ['retention_team_leader', 'retention_ldr', 'F2', 'not a number'],
            ['retention_team_leader', 'retention_ldr', 'F2', null],
            ['retention_team_leader', 'retention_ldr', 'I2', '2098-02-30'],
            ['retention_team_leader', 'retention_ldr', 'F2', '=10000'],
            ['retention_team_leader', 'retention_ldr', 'A2', 'bad account'],
            ['nsf_team_leader', 'nsf_ldr', 'G2', null],
            ['nsf_team_leader', 'nsf_ldr', 'C2', '2098-08-01'],
            ['nsf_team_leader', 'nsf_ldr', 'C2', 'invalid date'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidArchivedDetail')]
    public function test_invalid_detail_never_becomes_zero_pay(string $kind, string $key, string $cell, mixed $value): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources[$key]['path'], static fn ($book) => $book->getSheet(0)->setCellValue($cell, $value));
        $this->expectException(\RuntimeException::class);
        $this->command()->buildPayrollReviewLeadershipSnapshot($kind, '2098-09-01', '2098-09-30', $sources);
    }

    public function test_missing_named_detail_sheet_is_not_replaced_by_active_summary_sheet(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['nsf_ldr']['path'], static fn ($book) => $book->getSheet(0)->setTitle('Unknown Detail'));
        $this->expectException(\RuntimeException::class);
        $this->command()->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
    }

    public function test_nsf_workbooks_must_share_any_present_source_run_identity(): void
    {
        $sources = $this->sources();
        foreach (['ldr' => 'a', 'plaw' => 'b'] as $source => $letter) $this->changeWorkbook($sources['nsf_' . $source]['path'], static fn ($book) => $book->getProperties()->setCustomProperty('NSF Run ID', str_repeat($letter, 32)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inconsistent source run');
        $this->command()->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
    }

    public function test_nsf_run_status_sheet_cannot_contradict_the_document_property(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['nsf_ldr']['path'], static function ($book): void {
            $book->getProperties()->setCustomProperty('NSF Run ID', str_repeat('a', 32));
            $book->createSheet()->setTitle('Run Status')->fromArray([['Run ID', str_repeat('b', 32)]], null, 'A1');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source run identity');
        $this->command()->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
    }

    public function test_an_explicit_empty_archived_nsf_roster_does_not_fall_back_to_current_roster(): void
    {
        $sources = $this->sources();
        foreach (['ldr', 'plaw'] as $source) $this->changeWorkbook($sources['nsf_' . $source]['path'], static function ($book): void {
            $sheet = $book->getSheetByName('Agent Summary');
            $sheet->removeRow(2, $sheet->getHighestDataRow() - 1);
        });
        $command = $this->command();
        $result = $command->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
        $this->assertSame([], $result['team']);
        $this->assertSame(0.0, $result['summary']['totalPay']);
        $this->assertNull((new \ReflectionProperty($command, 'anthonyRosterCache'))->getValue($command));
    }

    public function test_duplicate_accounts_within_one_workbook_are_rejected_before_cross_company_deduplication(): void
    {
        $sources = $this->sources();
        $this->changeWorkbook($sources['retention_ldr']['path'], static function ($book): void {
            $sheet = $book->getSheet(0);
            $sheet->fromArray([[101, 'Duplicate Client', 'Synthetic Agent', '2098-09-01', 'RETAINED', 10000, 2, '2098-09-10', '2098-09-02']], null, 'A3');
        });
        $this->expectException(\RuntimeException::class);
        $this->command()->buildPayrollReviewLeadershipSnapshot('retention_team_leader', '2098-09-01', '2098-09-30', $sources);
    }

    public function test_period_and_report_kind_are_exact_and_each_family_requires_both_sources(): void
    {
        foreach ([['retention_team_leader', '2098-09-02', '2098-09-30'], ['retention_team_leader', '2098-09-01', '2098-10-01'], ['nsf', '2098-09-01', '2098-09-30']] as [$kind, $start, $end]) {
            try { $this->command()->buildPayrollReviewLeadershipSnapshot($kind, $start, $end, []); $this->fail('Invalid report scope accepted'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $sources = $this->sources();
        unset($sources['nsf_plaw']);
        $this->expectException(\RuntimeException::class);
        $this->command()->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', '2098-09-01', '2098-09-30', $sources);
    }
}
