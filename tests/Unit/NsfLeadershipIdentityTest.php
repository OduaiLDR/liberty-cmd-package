<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\NsfReportReadiness;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/** The real producer creates two company readiness IDs inside a separate orchestration capture. */
final class NsfLeadershipIdentityTest extends TestCase
{
    private const PERIOD = '2026-10-01';
    private const OUTER = 'cccccccccccccccccccccccccccccccc';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nsf-leader-ids-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700); mkdir($this->directory . '/storage/app', 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else { chmod($file->getPathname(), 0600); unlink($file->getPathname()); } }
        rmdir($this->directory);
    }

    private function sources(): array
    {
        $previous = Container::getInstance(); $app = new Application($this->directory);
        $app->useStoragePath($this->directory . '/storage');
        try {
            $archive = new PayrollReportArtifactArchive($this->directory . '/archive'); $artifacts = [];
            foreach (['ldr' => 'a', 'plaw' => 'b'] as $source => $id) {
                $data = [];
                for ($i = 1; $i <= 250; $i++) $data[] = ['ID' => ($source === 'ldr' ? 1000 : 2000) + $i,
                    'AGENT' => 'Synthetic NSF Agent', 'NSF_RETURNED_DATE' => '2026-10-01', 'NSF_ACTION' => 'Resubmitted',
                    'NSF_RECOUP_DATE' => '2026-10-02', 'CLEARED_DATE' => '2026-10-03', 'valid_commission' => true];
                $file = (new Formatter)->buildWorkbook($data, [['agent' => 'Synthetic NSF Agent', 'assignments' => 250, 'actions' => 250,
                    'ratio' => 1, 'actions_tier' => 3, 'cleared_tier' => 3, 'rate' => 4, 'clears' => 250, 'commission' => 1000,
                    'location' => 'Jordan', 'company' => $source === 'ldr' ? 'LDR' : 'Progress Law']],
                    $source, self::PERIOD, '2026-10-31', null, $source, [],
                    NsfReportReadiness::timing(self::PERIOD, new \DateTimeImmutable('2026-11-03T20:00:00Z')) + ['runId' => str_repeat($id, 32)]);
                $artifacts['nsf_' . $source] = $archive->capture('nsf_' . $source, self::PERIOD, self::OUTER, $file,
                    ['commissionResults' => PayrollCommissionResultSnapshot::component('nsf', $source, self::PERIOD, 'commission',
                        [['agent' => 'Synthetic NSF Agent', 'amount' => 1000]], PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS)]);
            }
            return $artifacts;
        } finally { Container::setInstance($previous); }
    }

    private function snapshot(array $sources): array
    {
        return (new GenerateRetentionManagerCommission)->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2026-10-31', $sources);
    }

    private function change(array &$artifact, callable $change): void
    {
        $book = IOFactory::load($artifact['path']);
        try { $change($book); chmod($artifact['path'], 0600); (new Xlsx($book))->save($artifact['path']); }
        finally { $book->disconnectWorksheets(); }
        $artifact['sha256'] = hash_file('sha256', $artifact['path']);
    }

    public function test_actual_formatter_with_three_distinct_ids_calculates_anthony_from_archived_roster(): void
    {
        $sources = $this->sources(); $before = array_column($sources, 'sha256');
        $snapshot = $this->snapshot($sources);
        $this->assertSame(350.0, $snapshot['summary']['totalPay']);
        $this->assertSame(500, $snapshot['summary']['clearCount']);
        $this->assertSame(['ldr' => str_repeat('a', 32), 'plaw' => str_repeat('b', 32)], $snapshot['nsfReadinessRunIds']);
        $this->assertSame(self::OUTER, $sources['nsf_ldr']['identity']);
        $this->assertSame($snapshot, $this->snapshot($sources));
        $this->assertSame($before, array_map(static fn ($a) => hash_file('sha256', $a['path']), array_values($sources)));
    }

    public static function invalidMetadata(): array
    {
        return [
            ['bad_id'], ['bad_status'], ['property_status_contradicts_timing'], ['sheet_id_contradicts_property'],
            ['sheet_status_contradicts_property'], ['sheet_company_differs'], ['sheet_period_differs'],
            ['saved_final_cutoff_differs'], ['one_company_id_missing'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMetadata')]
    public function test_malformed_or_inconsistent_company_readiness_metadata_stays_blocked(string $case): void
    {
        $sources = $this->sources();
        $this->change($sources['nsf_ldr'], static function ($book) use ($case): void {
            $properties = $book->getProperties();
            if ($case === 'bad_id') $properties->setCustomProperty(Formatter::PROPERTY_RUN_ID, 'not-a-run');
            if ($case === 'bad_status') $properties->setCustomProperty(Formatter::PROPERTY_RUN_STATUS, 'COMPLETED');
            if ($case === 'property_status_contradicts_timing') $properties->setCustomProperty(Formatter::PROPERTY_RUN_STATUS, 'FINAL');
            if ($case === 'saved_final_cutoff_differs') $properties->setCustomProperty('NSF Final Runs After (UTC)', '2026-10-06 07:00:00.000000');
            if ($case === 'one_company_id_missing') $properties->setCustomProperty(Formatter::PROPERTY_RUN_ID, null);
            if (str_starts_with($case, 'sheet_')) {
                $sheet = $book->createSheet()->setTitle('Run Status');
                $sheet->fromArray(match ($case) {
                    'sheet_id_contradicts_property' => [['Run ID', str_repeat('d', 32)]],
                    'sheet_status_contradicts_property' => [['NSF Report Run Status', 'FINAL-CUTOFF DATA']],
                    'sheet_company_differs' => [['Source', 'Progress Law']],
                    'sheet_period_differs' => [['Period Start', '2026-09-01']],
                }, null, 'A1');
            }
        });
        $this->expectException(\RuntimeException::class); $this->snapshot($sources);
    }

    public function test_outer_captures_must_still_match_each_other(): void
    {
        $sources = $this->sources(); $sources['nsf_plaw']['identity'] = str_repeat('d', 32);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('different orchestration captures'); $this->snapshot($sources);
    }

    public function test_wrong_artifact_company_and_period_are_not_read_as_this_capture(): void
    {
        $original = $this->sources();
        foreach ([['key' => 'nsf_plaw'], ['period' => '2026-09-01']] as $change) {
            $sources = $original; $sources['nsf_ldr'] = array_replace($sources['nsf_ldr'], $change);
            try { $this->snapshot($sources); $this->fail('Foreign source accepted.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('company, period or hash', $error->getMessage()); }
        }
    }
}
