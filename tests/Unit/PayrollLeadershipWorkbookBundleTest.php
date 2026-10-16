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
}
