<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Services\RetentionCommissionTierStore as Tiers;
use Cmd\Reports\Services\RetentionPricingSnapshot;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Exercise the real summary selections, XLSX writer and immutable archive. */
final class RetentionPricingWorkbookTest extends TestCase
{
    private string $root;
    private Container $previous;
    private mixed $previousLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/retention-pricing-book-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/app', 0700, true);
        $this->previous = Container::getInstance();
        $this->previousLog = Log::getFacadeRoot();
        Log::swap(new class {
            public function error(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
        });
        Container::setInstance(new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        Container::setInstance($this->previous);
        if ($this->previousLog !== null) Log::swap($this->previousLog);
        else Log::clearResolvedInstance('log');
        parent::tearDown();
    }

    private function rows(): array
    {
        return [
            ['ID' => '1000', 'RETENTION_AGENT' => 'Jane Doe', 'RETENTION_DATE' => '2098-02-06', 'CANCEL_REQUEST_DATE' => '2098-02-05',
                'RETENTION_PAYMENT_DATE' => '2098-02-28 23:59:59', 'T1' => 1.01, 'T2' => 2.02, 'T3' => 3.03, 'T4' => 4.04],
            ['ID' => '1001', 'RETENTION_AGENT' => '  JANE   Doe ', 'RETENTION_DATE' => '2098-01-06', 'CANCEL_REQUEST_DATE' => '2098-01-05',
                'RETENTION_PAYMENT_DATE' => '2098-02-10', 'T1' => 10.01, 'T2' => 20.02, 'T3' => 30.03, 'T4' => 40.04],
            ['ID' => '1002', 'RETENTION_AGENT' => 'Joe Zero', 'RETENTION_DATE' => '2098-01-07',
                'RETENTION_PAYMENT_DATE' => '2098-02-11', 'T1' => 5.01, 'T2' => 6.02, 'T3' => 7.03, 'T4' => 8.04],
            ['ID' => '1003', 'RETENTION_AGENT' => 'Jane Doe', 'RETENTION_DATE' => '', 'RETAINED_DATE' => '2098-01-06',
                'RETENTION_PAYMENT_DATE' => '2098-02-11', 'T1' => 500, 'T3' => 500],
            ['ID' => '1004', 'RETENTION_AGENT' => 'Jane Doe', 'RETENTION_DATE' => '2098-01-06',
                'RETENTION_PAYMENT_DATE' => '2098-03-01 00:00:00', 'T1' => 900, 'T3' => 900],
            ['ID' => '1005', 'RETENTION_AGENT' => 'Outside Agent', 'RETENTION_DATE' => '2097-12-06',
                'RETENTION_PAYMENT_DATE' => '2098-03-01', 'T1' => 900, 'T3' => 900],
            ['ID' => '1006', 'RETENTION_AGENT' => 'Grace Unassigned', 'RETENTION_DATE' => '2098-01-08',
                'RETENTION_PAYMENT_DATE' => '2098-02-01', 'T1' => 7.01, 'T2' => 8.02, 'T3' => 9.03, 'T4' => 10.04],
        ];
    }

    private function map(): array
    {
        return [Tiers::tierMapKey('2098-01-01', 'Jane Doe') => 1,
            Tiers::tierMapKey('2098-02-01', 'Jane Doe') => 1, // Deliberately stale: current calculated tier wins.
            Tiers::tierMapKey('2098-01-01', 'Joe Zero') => 0,
            Tiers::tierMapKey('2098-01-01', 'Grace Unassigned') => 2];
    }

    private function workbook(array $map, bool $enabled = true, bool $t4 = true): array
    {
        $command = new GenerateRetentionCommissionReport;
        $file = (new ReflectionMethod($command, 'buildWorkbook'))->invoke($command, $this->rows(),
            ['has_t4' => $t4, 'agents' => ['Jane Doe', 'Joe Zero', 'Outside Agent', 'Grace Unassigned']],
            'LDR', '2098-02-01', '2098-02-28', [], null, $map, $enabled, false, ['Jane Doe', 'Joe Zero'], 'ldr');
        $summary = (new ReflectionProperty($command, 'lastSummaryRows'))->getValue($command);
        $lines = array_values((new ReflectionProperty($command, 'lastPricingRows'))->getValue($command));
        $totals = [];
        foreach ($summary as $agent => $row) $totals[] = ['agent' => $agent, 'amount' => $row['commission']];
        $component = PayrollCommissionResultSnapshot::component('retention', 'ldr', '2098-02-01', 'commission', $totals);
        return [$file, $summary, $lines, $component];
    }

    public function test_same_pricing_pass_freezes_mixed_months_zero_tier_and_unassigned_earnings(): void
    {
        [$file, $summary, $lines, $component] = $this->workbook($this->map());
        $this->assertEqualsWithDelta(13.04, $summary['Jane Doe']['commission'], 0.000001);
        $this->assertEqualsWithDelta(8.02, $summary['Grace Unassigned']['commission'], 0.000001);
        $this->assertSame(0.0, $summary['Joe Zero']['commission']);
        $this->assertSame([2, 3, 4, 8], array_column($lines, 'detailRow'));
        $this->assertSame([3, 1, 0, 2], array_column($lines, 'appliedTier'));
        $this->assertSame([3.03, 10.01, 0.0, 8.02], array_column($lines, 'amount'));
        $proof = RetentionPricingSnapshot::capture('ldr', '2098-02-01', true, $lines, $component);
        $book = IOFactory::load($file['path']);
        try {
            $data = $book->getSheetByName('Retention Data');
            $this->assertSame('Cancel Request Date', $data->getCell('O1')->getValue());
            $this->assertSame(['Applied Tier Month', 'Applied Tier', 'Payable Commission', 'Paid This Period'],
                $data->rangeToArray('P1:S1')[0]);
            $this->assertSame('2098-02-01', $data->getCell('P2')->getValue());
            $this->assertSame(3, $data->getCell('Q2')->getValue());
            $this->assertEqualsWithDelta(3.03, $data->getCell('R2')->getValue(), 0.000001);
            $this->assertSame('2098-01-01', $data->getCell('P3')->getValue());
            $this->assertSame(1, $data->getCell('Q3')->getValue());
            $this->assertSame(0, $data->getCell('Q4')->getValue());
            $this->assertSame('Yes', $data->getCell('S4')->getValue(), 'Paid at tier zero differs from not payable');
            foreach ([5, 6, 7] as $row) {
                $this->assertNull($data->getCell('Q' . $row)->getValue());
                $this->assertSame(0, $data->getCell('R' . $row)->getValue());
                $this->assertSame('No', $data->getCell('S' . $row)->getValue());
            }
            $this->assertSame('Yes', $data->getCell('S8')->getValue(), 'Unrostered earnings keep their actual selected price');
            $this->assertSame(['Retention Data', 'Commission Summary'], $book->getSheetNames());
        } finally { $book->disconnectWorksheets(); }
        $archive = new PayrollReportArtifactArchive($this->root . '/archive');
        $stored = $archive->capture('retention_ldr', '2098-02-01', str_repeat('a', 32), $file,
            ['commissionResults' => $component, 'regularPricing' => $proof]);
        $map = $this->map();$map[Tiers::tierMapKey('2098-01-01', 'Jane Doe')] = 3;
        [, $newSummary] = $this->workbook($map);
        $this->assertEqualsWithDelta(33.06, $newSummary['Jane Doe']['commission'], 0.000001);
        $frozen = $archive->read('retention_ldr', '2098-02-01', str_repeat('a', 32));
        $this->assertSame($stored['sha256'], $frozen['sha256']);
        $this->assertEquals($proof, RetentionPricingSnapshot::validate($frozen['context']['regularPricing'], $component));
        $this->assertSame(1, $frozen['context']['regularPricing']['rows'][1]['appliedTier']);
    }

    public function test_explicit_override_records_the_actual_current_tier_without_repricing_history(): void
    {
        [$file, $summary, $lines, $component] = $this->workbook($this->map(), false);
        $proof = RetentionPricingSnapshot::capture('ldr', '2098-02-01', false, $lines, $component);
        $this->assertSame('report_month_override', $proof['policy']);
        $this->assertSame(3, $proof['rows'][1]['appliedTier']);
        $this->assertSame(30.03, $proof['rows'][1]['amount']);
        $this->assertEqualsWithDelta(33.06, $summary['Jane Doe']['commission'], 0.000001);
        $book = IOFactory::load($file['path']);
        try { $this->assertSame('2098-02-01', $book->getSheetByName('Retention Data')->getCell('P3')->getValue()); }
        finally { $book->disconnectWorksheets(); }
    }

    public function test_pre_t4_workbooks_keep_cancel_column_and_append_evidence_after_it(): void
    {
        [$file, , $lines, $component] = $this->workbook($this->map(), true, false);
        RetentionPricingSnapshot::capture('ldr', '2098-02-01', true, $lines, $component);
        $book = IOFactory::load($file['path']);
        try {
            $data = $book->getSheetByName('Retention Data');
            $this->assertSame('Cancel Request Date', $data->getCell('N1')->getValue());
            $this->assertSame('Applied Tier Month', $data->getCell('O1')->getValue());
            $this->assertSame('Paid This Period', $data->getCell('R1')->getValue());
        } finally { $book->disconnectWorksheets(); }
    }

    public function test_missing_historical_tier_stops_the_workbook_before_any_source_can_publish(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing retained-month tier');
        $this->workbook([]);
    }

    public function test_existing_leadership_reader_sees_identical_source_rows_with_appended_pricing_columns(): void
    {
        [$file] = $this->workbook($this->map());
        $leadership = new GenerateRetentionManagerCommission;
        $leadership->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $read = new ReflectionMethod($leadership, 'loadRetentionReportRowsFromXlsx');
        $newRows = $read->invoke($leadership, $file['path'], 'LDR');
        $book = IOFactory::load($file['path']);
        try {
            $book->getSheetByName('Retention Data')->removeColumn('P', 4);
            $legacyPath = $this->root . '/app/legacy-columns.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($legacyPath);
        } finally { $book->disconnectWorksheets(); }
        $this->assertCount(7, $newRows);
        $this->assertSame($read->invoke($leadership, $legacyPath, 'LDR'), $newRows);
    }
}
