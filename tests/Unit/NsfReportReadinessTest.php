<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\Formatter;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\NsfReportReadiness;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class NsfReportReadinessTest extends TestCase
{
    public function test_cutoff_is_sixth_midnight_pacific_with_dst_and_year_rollover(): void
    {
        foreach ([
            ['2098-09-01', '2098-10-06 07:00:00.000000'],
            ['2098-01-01', '2098-02-06 08:00:00.000000'],
            ['2098-11-01', '2098-12-06 08:00:00.000000'],
            ['2098-12-01', '2099-01-06 08:00:00.000000'],
        ] as [$period, $expected]) {
            $this->assertSame($expected, NsfReportReadiness::timing($period)['finalAfter']);
        }
        $before = NsfReportReadiness::timing('2098-09-01', new \DateTimeImmutable('2098-10-06T06:59:59.999999Z'));
        $at = NsfReportReadiness::timing('2098-09-01', new \DateTimeImmutable('2098-10-06T07:00:00Z'));
        $this->assertTrue($before['isProvisional']);
        $this->assertFalse($at['isProvisional']);
        $this->assertSame('2098-10-05 23:59:59 PDT', $at['cutoffPacific']);
    }

    public function test_completed_preview_stays_provisional_until_a_new_after_cutoff_run(): void
    {
        $sql = new ReadinessConnector;
        $preview = NsfReportReadiness::begin($sql, 'ldr', '2098-09-01', new \DateTimeImmutable('2098-10-03T12:00:00Z'));
        $this->assertSame('running', $sql->row['Status']);
        $this->assertNull($sql->row['Completed_At']);
        NsfReportReadiness::complete($sql, $preview);
        $this->assertSame('completed', $sql->row['Status']);
        $this->assertNotNull($sql->row['Completed_At']);
        $this->assertTrue($sql->row['Started_At'] < $sql->row['Final_After']);
        // Completion time is after the cutoff, but readiness is anchored to start.
        $this->assertGreaterThan($sql->row['Final_After'], $sql->row['Completed_At']);
        $final = NsfReportReadiness::begin($sql, 'ldr', '2098-09-01', new \DateTimeImmutable('2098-10-06T07:00:00Z'));
        $this->assertNotSame($preview['runId'], $final['runId']);
        $this->assertNull($sql->row['Completed_At']);
        NsfReportReadiness::complete($sql, $final);
        $this->assertFalse($final['isProvisional']);
        $this->assertSame($sql->row['Started_At'], $sql->row['Final_After']);
    }

    public function test_failed_run_invalidates_readiness_and_stale_run_cannot_mark_new_run_ready(): void
    {
        $sql = new ReadinessConnector;
        $old = NsfReportReadiness::begin($sql, 'plaw', '2098-01-01');
        NsfReportReadiness::complete($sql, $old);
        NsfReportReadiness::fail($sql, $old);
        $this->assertSame('failed', $sql->row['Status']);
        $this->assertNull($sql->row['Completed_At']);
        $new = NsfReportReadiness::begin($sql, 'plaw', '2098-01-01');
        foreach (['complete', 'fail'] as $operation) {
            try {
                NsfReportReadiness::$operation($sql, $old);
                $this->fail('Old run must not overwrite a new readiness marker.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('confirmation failed', $error->getMessage());
            }
            $this->assertSame($new['runId'], $sql->row['Run_Id']);
            $this->assertSame('running', $sql->row['Status']);
        }
    }

    public function test_failed_schema_write_read_and_completion_are_not_silent_success(): void
    {
        foreach (['IF OBJECT_ID', 'MERGE', 'SELECT'] as $prefix) {
            $sql = new ReadinessConnector;
            $sql->failPrefix = $prefix;
            try {
                NsfReportReadiness::begin($sql, 'ldr', '2098-01-01');
                $this->fail('Readiness failure must block the run.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('payroll must remain blocked', $error->getMessage());
            }
        }
        $sql = new ReadinessConnector;
        $run = NsfReportReadiness::begin($sql, 'ldr', '2098-01-01');
        $sql->failPrefix = 'UPDATE';
        $this->expectException(\RuntimeException::class);
        NsfReportReadiness::complete($sql, $run);
    }

    public function test_invalid_period_never_writes(): void
    {
        $sql = new ReadinessConnector;
        try {
            NsfReportReadiness::begin($sql, 'ldr', '2098-13-01');
            $this->fail('Invalid month accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $sql->calls);
        }
    }

    public function test_workbook_run_status_is_visible_without_changing_data_contract(): void
    {
        $root = sys_get_temp_dir() . '/nsf-readiness-' . bin2hex(random_bytes(8));
        mkdir($root . '/app', 0777, true);
        $original = Container::getInstance();
        Container::setInstance(new class($root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
        $file = null;
        try {
            foreach (['2098-10-03T12:00:00Z' => true, '2098-10-06T07:00:00Z' => false] as $at => $provisional) {
                $timing = NsfReportReadiness::timing('2098-09-01', new \DateTimeImmutable($at)) + ['runId' => 'synthetic-run-id'];
                $file = (new Formatter)->buildWorkbook([], [], 'LDR', '2098-09-01', '2098-09-30', null, 'ldr', [], $timing);
                $this->assertSame('NSF Commission Report - LDR - 09-2098 - All.xlsx', $file['filename']);
                $book = IOFactory::load($file['path']);
                try {
                    $this->assertSame(['NSF Data', 'Agent Summary', 'Run Status'], $book->getSheetNames());
                    $this->assertSame($provisional ? 'Run Status' : 'NSF Data', $book->getActiveSheet()->getTitle());
                    $status = $book->getSheetByName('Run Status');
                    $this->assertSame($provisional ? 'PROVISIONAL — NOT READY FOR FINAL PAYROLL' : 'FINAL-CUTOFF DATA', $status->getCell('B1')->getValue());
                    $this->assertSame('2098-10-05 23:59:59 PDT', $status->getCell('B5')->getValue());
                    $this->assertSame($timing['startedAt'], $status->getCell('B6')->getValue());
                    $this->assertSame('synthetic-run-id', $status->getCell('B8')->getValue());
                    $this->assertSame($timing['finalAfter'], $status->getCell('B9')->getValue());
                    $this->assertSame('ID', $book->getSheetByName('NSF Data')->getCell('A1')->getValue());
                } finally { $book->disconnectWorksheets(); }
            }
        } finally {
            if ($file !== null && is_file($file['path'])) unlink($file['path']);
            rmdir($root . '/app'); rmdir($root);
            Container::setInstance($original);
        }
    }
}

/** In-memory SQL contract simulator: it has no underlying database connection. */
final class ReadinessConnector extends DBConnector
{
    public array $row = [];
    public array $calls = [];
    public ?string $failPrefix = null;
    public function __construct() {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->calls[] = [$sql, $params];
        if ($this->failPrefix !== null && str_starts_with($sql, $this->failPrefix)) return ['success' => false];
        if (str_starts_with($sql, 'MERGE')) {
            $this->row = ['Source' => $params[0], 'Period_Start' => $params[1], 'Status' => 'running',
                'Run_Id' => $params[2], 'Started_At' => $params[3], 'Final_After' => $params[4], 'Completed_At' => null];
        } elseif (str_starts_with($sql, 'UPDATE') && ($this->row['Run_Id'] ?? null) === $params[2]) {
            $completed = str_contains($sql, "Status='completed'");
            if (!$completed || $this->row['Status'] === 'running') {
                $this->row['Status'] = $completed ? 'completed' : 'failed';
                $this->row['Completed_At'] = $completed ? '2099-01-01 00:00:00.000000' : null;
            }
        }
        return ['success' => true, 'data' => str_starts_with($sql, 'SELECT') && $this->row ? [$this->row] : [], 'row_count' => 1];
    }
}
