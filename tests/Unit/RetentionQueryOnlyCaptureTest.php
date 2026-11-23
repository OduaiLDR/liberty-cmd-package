<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\CompareRetentionLeadershipShadow;
use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class RetentionQueryOnlyCaptureTest extends TestCase
{
    private const PERIOD = '2098-02-01';
    private const END = '2098-02-28';
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHADOW_OPTIONS = ['--structured-shadow' => true, '--no-azure-write' => true, '--no-email' => true];

    private string $root;
    private Container $app;
    private Container $previousApp;
    private mixed $previousLog;
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/retention-shadow-' . bin2hex(random_bytes(8));
        mkdir(dirname($this->defaultSnapshotPath('LDR')), 0700, true);
        foreach (['LDR', 'Progress Law'] as $display) {
            file_put_contents($this->defaultSnapshotPath($display), 'prior snapshot for ' . $display);
        }

        $this->previousApp = Container::getInstance();
        $this->previousLog = Log::getFacadeRoot();
        $this->app = new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
            public function runningUnitTests(): bool { return true; }
        };
        Container::setInstance($this->app);
        Log::swap(new NullLogger);

        foreach ([
            'PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT' => '1',
            'PAYROLL_COMMISSION_RUN_ID' => self::RUN_ID,
            'RETENTION_TIER_BY_RETAINED_MONTH' => 'true',
        ] as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Container::setInstance($this->previousApp);
        if ($this->previousLog === null) {
            Log::clearResolvedInstance('log');
        } else {
            Log::swap($this->previousLog);
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                rmdir($path);
            } else {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_same_run_workbooks_and_structured_rows_give_the_same_rama_and_nick_snapshots(): void
    {
        $artifacts = $this->capturePair();
        $leader = new GenerateRetentionManagerCommission;

        $legacy = [];
        foreach (['retention_manager', 'retention_team_leader'] as $kind) {
            $legacy[$kind] = $leader->buildPayrollReviewLeadershipSnapshot($kind, self::PERIOD, self::END, $artifacts);
        }

        $structured = [];
        foreach ($legacy as $kind => $expected) {
            $structured[$kind] = $leader->buildPayrollReviewRetentionStructuredSnapshot($kind, self::PERIOD, self::RUN_ID, $artifacts);
            self::assertSame($expected, $structured[$kind]);
        }

        $rama = $structured['retention_manager']['summary'];
        self::assertSame(7, $rama['assignedCount']);
        self::assertSame(4, $rama['retainedCount']);
        self::assertSame(4, $rama['bonusAssignedCount']);
        self::assertSame(2, $rama['bonusRetainedCount']);
        self::assertSame(1000, $rama['commission']);
        self::assertSame(750, $rama['bonusCommission']);

        $nick = $structured['retention_team_leader'];
        self::assertSame(7, $nick['summary']['assignedCount']);
        self::assertSame(4, $nick['summary']['retainedCount']);
        self::assertSame(3, $nick['summary']['tier']);
        self::assertSame(38.0, $nick['summary']['totalCommission']);
        self::assertSame([
            'Andrea Mendoza' => 8.0,
            'Last Day Unretained' => 0.0,
            'LDR Same Day' => 10.0,
            'Dropped Before Payment' => 0.0,
            'Future Payment' => 9.0,
            'Missing Outcome' => 0.0,
            'PLAW Earlier Day' => 11.0,
        ], array_column($nick['lines'], 'commissionTotal', 'employeeName'));

        $ldr = $artifacts['retention_ldr']['context'];
        $plaw = $artifacts['retention_plaw']['context'];
        $ldrRows = array_column($ldr['retentionStructuredCapture']['rows'], null, 'ID');
        $plawRows = array_column($plaw['retentionStructuredCapture']['rows'], null, 'ID');
        self::assertEqualsCanonicalizing([101, 102, 103, 104], array_keys($ldrRows));
        self::assertEqualsCanonicalizing([102, 103, 106, 108, 109], array_keys($plawRows));
        self::assertSame(self::PERIOD, $ldrRows[101]['CANCEL_REQUEST_DATE']);
        self::assertNull($ldrRows[101]['RETENTION_PAYMENT_DATE']);
        self::assertSame(self::END, $ldrRows[104]['CANCEL_REQUEST_DATE']);
        self::assertSame('2098-03-01', $plawRows[106]['RETENTION_PAYMENT_DATE']);
        self::assertSame('retained', $ldrRows[101]['IMMEDIATE_RESULTS']);
        self::assertEquals(60000.01, $plawRows[103]['ENROLLED_DEBT']);
        self::assertSame([
            'TRANCHE' => '53',
            'CUT_OFF' => self::PERIOD,
            'MADE_CUT_OFF' => true,
            'BONUS_FLAG' => 'Bonus',
        ], $ldr['trancheByClient']['LLG-101']);
        self::assertSame('No Bonus', $ldr['trancheByClient']['LLG-104']['BONUS_FLAG']);
        self::assertSame('', $ldr['trancheByClient']['LLG-103']['BONUS_FLAG']);
        self::assertSame('Bonus', $plaw['trancheByClient']['LLG-103']['BONUS_FLAG']);
        self::assertSame('No Bonus', $plaw['trancheByClient']['LLG-108']['BONUS_FLAG']);

        $book = IOFactory::load($artifacts['retention_ldr']['path']);
        try {
            self::assertSame(['Retention Data', 'Commission Summary'], $book->getSheetNames());
            $sheet = $book->getSheetByName('Retention Data');
            self::assertSame('Cancel Request Date', $sheet->getCell('O1')->getValue());
            $rows = array_column($sheet->rangeToArray('A2:S' . $sheet->getHighestDataRow(), null, false, false), null, 0);
            self::assertCount(5, $rows);
            self::assertSame('No', $rows[101][18]);
            self::assertSame('No', $rows[104][18]);
            self::assertEquals(['2098-01-01', 1, 5.0, 'Yes'], array_slice($rows[105], 15));
        } finally {
            $book->disconnectWorksheets();
        }

        [$code, $stdout, $stderr] = $this->runComparator();
        self::assertSame(Command::SUCCESS, $code, $stderr);
        self::assertSame('', $stderr);
        $comparison = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($legacy), array_keys($comparison));
        foreach ($comparison as $report) {
            self::assertSame(['matches', 'workbookMs', 'structuredMs'], array_keys($report));
            self::assertTrue($report['matches']);
            self::assertGreaterThanOrEqual(0, $report['workbookMs']);
            self::assertGreaterThanOrEqual(0, $report['structuredMs']);
        }

        $metadata = [];
        foreach ($artifacts as $key => $artifact) {
            $path = $artifact['path'];
            chmod($path, 0600);
            unlink($path);
            self::assertFileDoesNotExist($path);
            $metadata[$key] = json_decode(file_get_contents(dirname($path) . '/artifact.json'), true, flags: JSON_THROW_ON_ERROR);
        }
        foreach ($structured as $kind => $expected) {
            self::assertSame($expected, $leader->buildPayrollReviewRetentionStructuredSnapshot($kind, self::PERIOD, self::RUN_ID, $metadata));
        }

        [$code, $stdout, $stderr] = $this->runComparator();
        self::assertSame(Command::FAILURE, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('valid paired shadow capture', $stderr);
    }

    public function test_midnight_projection_preserves_the_bonus_frozen_from_the_source_calendar_date(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $artifacts = $this->capturePair();
            $plaw = $artifacts['retention_plaw']['context'];
            $plawRows = array_column($plaw['retentionStructuredCapture']['rows'], null, 'ID');
            self::assertSame(self::PERIOD, $plawRows[110]['CANCEL_REQUEST_DATE']);
            self::assertSame('2098-01-31', $plaw['trancheByClient']['LLG-110']['CUT_OFF']);
            self::assertTrue($plaw['trancheByClient']['LLG-110']['MADE_CUT_OFF']);
            self::assertSame('Bonus', $plaw['trancheByClient']['LLG-110']['BONUS_FLAG']);
            $leader = new GenerateRetentionManagerCommission;
            self::assertSame(
                $leader->buildPayrollReviewLeadershipSnapshot('retention_manager', self::PERIOD, self::END, $artifacts),
                $leader->buildPayrollReviewRetentionStructuredSnapshot('retention_manager', self::PERIOD, self::RUN_ID, $artifacts),
            );
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_normal_payroll_capture_preserves_leadership_and_cross_month_employee_pricing(): void
    {
        [$code, $output, $producer] = $this->runReport(['--no-email' => true], allowWrites: true);
        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(0, $producer->emails);
        self::assertSame(2, $producer->snapshotCopies);
        $archive = new PayrollReportArtifactArchive;
        $artifacts = [];
        foreach (['ldr', 'plaw'] as $source) {
            $key = 'retention_' . $source;
            $artifacts[$key] = $archive->read($key, self::PERIOD, self::RUN_ID);
            self::assertSame(2, $artifacts[$key]['context']['retentionStructuredCapture']['version']);
            self::assertSame('53', $artifacts[$key]['context']['trancheByClient']['LLG-' . ($source === 'ldr' ? '101' : '103')]['TRANCHE']);
        }
        $legacy = $artifacts;
        foreach ($legacy as &$artifact) {
            unset($artifact['context']['retentionStructuredCapture']);
            $artifact['contextSha256'] = hash('sha256', json_encode(
                $artifact['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        }
        unset($artifact);
        $read = static fn (array $sources): array => CustomerServiceCalculationSnapshot::forArchivedAgent(
            $sources, self::PERIOD, 'retention_regular', 'outside month paid', 'ldr',
        )['components'][0];
        $legacyPrice = $read($legacy);
        $structuredPrice = $read($artifacts);
        unset($legacyPrice['contextSha256'], $structuredPrice['contextSha256']);
        self::assertSame($legacyPrice, $structuredPrice);
        self::assertSame(['105'], array_column($structuredPrice['lines'], 'clientId'));
        self::assertSame(5.0, $structuredPrice['amount']);
        self::assertSame('2098-01-01', $structuredPrice['lines'][0]['retainedMonth']);
        $mixed = $artifacts;
        $mixed['retention_plaw']['context']['retentionStructuredCapture']['captureId'] = str_repeat('e', 32);
        $mixed['retention_plaw']['contextSha256'] = hash('sha256', json_encode(
            $mixed['retention_plaw']['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        try {
            $read($mixed);
            self::fail('Mixed invocations cannot supply a company-scoped employee statement.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('capture scope', $error->getMessage());
        }
        $leader = new GenerateRetentionManagerCommission;
        $legacyLeadership = [];
        foreach (['retention_manager', 'retention_team_leader'] as $kind) {
            $legacyLeadership[$kind] = $leader->buildPayrollReviewLeadershipSnapshot($kind, self::PERIOD, self::END, $legacy);
        }
        $nsf = [];
        foreach (['nsf_ldr', 'nsf_plaw'] as $key) {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $book->getActiveSheet()->setTitle('NSF Data')->setCellValue('A1', 'ID');
            $book->createSheet()->setTitle('Agent Summary')->setCellValue('A1', 'NSF Agent');
            $path = $this->root . '/' . $key . '.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $nsf[$key] = ['path' => $path];
        }
        $bundleSources = $artifacts;
        foreach (['ldr', 'plaw'] as $source) {
            foreach (['lookback' => 'bonusCommission', 'nsf' => 'commission'] as $family => $component) {
                $key = $family . '_' . $source;
                $kind = $family === 'nsf' ? 'nsf' : 'retention';
                $bundleSources[$key] = ['key' => $key, 'context' => ['commissionResults' =>
                    PayrollCommissionResultSnapshot::component($kind, $source, self::PERIOD, $component, [],
                        $family === 'nsf' ? PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS : null),
                ]] + $artifacts['retention_' . $source];
            }
        }
        $selections = [
            ['reportKind' => 'retention', 'agentKey' => 'outside month paid', 'sourceScope' => 'ldr'],
            ['reportKind' => 'retention', 'agentKey' => 'plaw earlier day', 'sourceScope' => 'plaw'],
        ];
        $batch = CustomerServiceCalculationSnapshot::forAgents($bundleSources, self::PERIOD, $selections);
        foreach ($selections as $index => $selection) {
            self::assertSame(['ok' => true, 'calculation' => CustomerServiceCalculationSnapshot::forAgent(
                $bundleSources, self::PERIOD, ...$selection,
            )], $batch[$index]);
        }
        self::assertSame($structuredPrice['lines'], $batch[0]['calculation']['components'][0]['lines']);

        $legacyBundle = $leader->buildPayrollReviewWorkbookBundle(self::PERIOD, self::END, $legacy + $nsf, $this->root . '/legacy-bundle');
        foreach ($artifacts as &$artifact) {
            @chmod($artifact['path'], 0600);
            self::assertTrue(unlink($artifact['path']));
        }
        unset($artifact);
        $replayedPrice = $read($artifacts);
        unset($replayedPrice['contextSha256']);
        self::assertSame($structuredPrice, $replayedPrice);
        foreach ($legacyLeadership as $kind => $snapshot) {
            self::assertSame($snapshot, $leader->buildPayrollReviewLeadershipSnapshot($kind, self::PERIOD, self::END, $artifacts));
        }
        $structuredBundle = $leader->buildPayrollReviewWorkbookBundle(self::PERIOD, self::END,
            $artifacts + $nsf, $this->root . '/structured-bundle');
        self::assertSame($legacyBundle['reports'], $structuredBundle['reports']);
        foreach (['customer_support_manager', 'retention_team_leader'] as $key) {
            $oldBook = IOFactory::load($legacyBundle['files'][$key]['path']);
            $newBook = IOFactory::load($structuredBundle['files'][$key]['path']);
            try {
                self::assertSame($oldBook->getSheetNames(), $newBook->getSheetNames());
                foreach ($oldBook->getSheetNames() as $sheet) {
                    self::assertSame($oldBook->getSheetByName($sheet)->toArray(), $newBook->getSheetByName($sheet)->toArray());
                }
            } finally {
                $oldBook->disconnectWorksheets();
                $newBook->disconnectWorksheets();
            }
        }
    }

    public function test_structured_reader_rejects_incomplete_or_mismatched_source_evidence(): void
    {
        $artifacts = $this->capturePair();
        $key = 'retention_plaw';
        $captureKey = 'retentionStructuredCapture';

        $bad = $artifacts;
        unset($bad[$key]);
        $this->assertRejected($bad, 'missing company');

        $bad = $artifacts;
        $bad[$key]['period'] = '2098-01-01';
        $this->assertRejected($bad, 'mismatched archive period');

        $bad = $artifacts;
        $bad[$key]['identity'] = str_repeat('b', 32);
        $this->assertRejected($bad, 'mismatched archive run');

        $bad = $artifacts;
        $bad[$key]['context'][$captureKey]['rows'][0]['ENROLLED_DEBT'] = 15000;
        $this->assertRejected($bad, 'changed source data with the original context hash');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context) use ($captureKey): array {
            $context[$captureKey]['source'] = 'ldr';
            return $context;
        }), 'wrong inner company');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context) use ($captureKey): array {
            $context[$captureKey]['captureId'] = str_repeat('b', 32);
            return $context;
        }), 'mixed capture attempts under the same outer run');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context) use ($captureKey): array {
            $context[$captureKey]['rows'][] = $context[$captureKey]['rows'][0];
            return $context;
        }), 'duplicate contact within one source');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context) use ($captureKey): array {
            $context[$captureKey]['rows'][0]['CANCEL_REQUEST_DATE'] = '2098-03-01';
            return $context;
        }), 'out-of-month structured row');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context): array {
            unset($context['trancheByClient']['LLG-103']);
            return $context;
        }), 'missing frozen context for the winning duplicate');

        $this->assertRejected($this->changeContext($artifacts, $key, static function (array $context): array {
            $context['trancheByClient']['LLG-103']['BONUS_FLAG'] = 'No Bonus';
            return $context;
        }), 'bonus flag contradicting the frozen cutoff and outcome');
    }

    public function test_existing_query_only_preview_does_not_opt_in_to_shadow_capture(): void
    {
        [$code, $output, $command, $connectors] = $this->runReport(['--no-azure-write' => true, '--no-email' => true]);
        self::assertSame(Command::SUCCESS, $code, $output);
        $this->assertIsolated($command, $connectors);
        self::assertDirectoryDoesNotExist($this->root . '/app/payroll-retention-shadow');
    }

    public function test_failed_second_company_requires_a_new_run_id_to_retry(): void
    {
        [$code, $output, $command, $connectors] = $this->runReport(self::SHADOW_OPTIONS, failPlawBaseRead: true);
        self::assertSame(Command::FAILURE, $code, $output);
        $this->assertIsolated($command, $connectors);
        $archive = new PayrollReportArtifactArchive($this->root . '/app/payroll-retention-shadow');
        self::assertFileExists($archive->read('retention_ldr', self::PERIOD, self::RUN_ID)['path']);
        [$code, $stdout] = $this->runComparator();
        self::assertSame(Command::FAILURE, $code);
        self::assertSame('', $stdout);

        [$code, $output, $command, $connectors] = $this->runReport(self::SHADOW_OPTIONS);
        self::assertSame(Command::FAILURE, $code, $output);
        $this->assertIsolated($command, $connectors);

        $retryRun = str_repeat('b', 32);
        putenv('PAYROLL_COMMISSION_RUN_ID=' . $retryRun);
        [$code, $output, $command, $connectors] = $this->runReport(self::SHADOW_OPTIONS);
        self::assertSame(Command::SUCCESS, $code, $output);
        $this->assertIsolated($command, $connectors);
        [$code, , $stderr] = $this->runComparator($retryRun);
        self::assertSame(Command::SUCCESS, $code, $stderr);
    }

    #[DataProvider('invalidModes')]
    public function test_invalid_shadow_opt_ins_stop_before_lookups(array $input, ?string $environment): void
    {
        if ($environment !== null) {
            putenv($environment);
        }
        [$code, $output, $command, $connectors] = $this->runReport($input);
        self::assertSame(Command::FAILURE, $code, $output);
        self::assertSame([], $command->connectorCalls);
        $this->assertIsolated($command, $connectors);
        self::assertDirectoryDoesNotExist($this->root . '/app/payroll-retention-shadow');
    }

    public static function invalidModes(): array
    {
        return [
            'commission write enabled' => [['--no-azure-write' => false] + self::SHADOW_OPTIONS, null],
            'email enabled' => [['--no-email' => false] + self::SHADOW_OPTIONS, null],
            'test recipient supplied' => [['--test-recipient' => 'synthetic@example.test'] + self::SHADOW_OPTIONS, null],
            'untrusted opt-in' => [self::SHADOW_OPTIONS, 'PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=0'],
            'invalid run ID' => [self::SHADOW_OPTIONS, 'PAYROLL_COMMISSION_RUN_ID=invalid'],
            'loose legacy period syntax' => [['period' => '2098-02-10'] + self::SHADOW_OPTIONS, null],
            'single company' => [['source' => 'ldr'] + self::SHADOW_OPTIONS, null],
        ];
    }

    private function capturePair(): array
    {
        [$code, $output, $command, $connectors] = $this->runReport(self::SHADOW_OPTIONS);
        self::assertSame(Command::SUCCESS, $code, $output);
        $this->assertIsolated($command, $connectors);

        $sqlReads = $connectors['ldr']->sqlReads;
        self::assertCount(2, array_filter($sqlReads, static fn (string $query): bool => str_contains($query, 'TblDebtTrancheSales')));
        self::assertCount(1, array_filter($sqlReads, static fn (string $query): bool => str_contains($query, 'TblRetentionCommissionTiers')));
        $archive = new PayrollReportArtifactArchive($this->root . '/app/payroll-retention-shadow');
        $artifacts = [];
        foreach (['ldr' => 'LDR', 'plaw' => 'Progress Law'] as $source => $display) {
            $key = 'retention_' . $source;
            $artifacts[$key] = $archive->read($key, self::PERIOD, self::RUN_ID);
            self::assertSame('Retention Commission (' . $display . ') - All.xlsx', $artifacts[$key]['fileName']);
            self::assertTrue($artifacts[$key]['context']['queryOnly']);
        }
        return $artifacts;
    }

    private function assertIsolated(GenerateRetentionCommissionReport $command, array $connectors): void
    {
        self::assertSame(0, $command->emails);
        self::assertSame(0, $command->snapshotCopies);
        foreach ($connectors as $connector) {
            self::assertSame([], $connector->unexpectedOperations);
        }
        foreach (['LDR', 'Progress Law'] as $display) {
            self::assertSame('prior snapshot for ' . $display, file_get_contents($this->defaultSnapshotPath($display)));
        }
        self::assertDirectoryDoesNotExist($this->root . '/app/payroll-report-artifacts');
        self::assertSame([], glob($this->root . '/app/retention-report-runs/*', GLOB_ONLYDIR));
    }

    private function defaultSnapshotPath(string $display): string
    {
        return $this->root . '/app/commission-snapshots/2098-02/retention/Retention Commission Report - ' . $display . ' - 02-2098.xlsx';
    }

    private function assertRejected(array $artifacts, string $case): void
    {
        try {
            (new GenerateRetentionManagerCommission)->buildPayrollReviewRetentionStructuredSnapshot(
                'retention_manager', self::PERIOD, self::RUN_ID, $artifacts,
            );
            self::fail('Structured reader accepted ' . $case);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            self::assertNotSame('', $error->getMessage(), $case);
        }
    }

    private function runComparator(string $runId = self::RUN_ID): array
    {
        $command = new CompareRetentionLeadershipShadow;
        $command->setLaravel($this->app);
        $output = new class extends ConsoleOutput {
            public string $captured = '';
            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured .= $message . ($newline ? PHP_EOL : '');
            }
        };
        $errors = new BufferedOutput;
        $output->setErrorOutput($errors);
        $input = new ArgvInput(['artisan', self::PERIOD, $runId], $command->getDefinition());
        $code = $command->run($input, $output);
        return [$code, $output->captured, $errors->fetch()];
    }

    private function changeContext(array $artifacts, string $key, callable $change): array
    {
        $context = $change($artifacts[$key]['context']);
        $artifacts[$key]['context'] = $context;
        $artifacts[$key]['contextSha256'] = hash('sha256', json_encode(
            $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        return $artifacts;
    }

    private function runReport(array $arguments, bool $failPlawBaseRead = false, bool $allowWrites = false): array
    {
        $connectors = [
            'ldr' => new RetentionShadowTestConnector([
                $this->row(101, ' Andrea   Mendoze ', '2098-02-01 00:00:00', 'retained', '15000.00', '2098-02-02'),
                $this->row(102, 'LDR Same Day', '2098-02-05 23:59:59', 'Retained', '30000.01', '2098-02-05'),
                $this->row(103, 'LDR Later Day', '2098-02-12', 'Retained', '12000', '2098-02-13'),
                $this->row(104, 'Last Day Unretained', (string) strtotime(self::END . ' 23:59:59') . '.000000000', 'Not Retained', '4555.55'),
                $this->row(105, 'Outside Month Paid', '2098-01-31 23:59:59', 'Retained', '18000', '2098-01-31'),
            ], [102 => '2098-02-06', 103 => '2098-02-15', 105 => '2098-02-03']),
            'plaw' => new RetentionShadowTestConnector([
                $this->row(101, 'Before Month Duplicate', '2098-01-31 23:59:59', 'Retained', '18000'),
                $this->row(102, 'PLAW Same Day', '2098-02-05 00:00:00', 'Not Retained', '18000'),
                $this->row(103, 'PLAW Earlier Day', '2098-02-04', 'Retained', '60000.01', '2098-02-09'),
                $this->row(106, 'Future Payment', '2098-02-27', 'Retained', '30000.00', '2098-02-28'),
                $this->row(107, 'After Month', '2098-03-01 00:00:00', 'Retained', '15000'),
                $this->row(108, 'Missing Outcome', '2098-02-11', null, '40000'),
                $this->row(109, 'Dropped Before Payment', '2098-02-19', 'Dropped', '60000', '2098-02-20', '2098-02-21'),
                $this->row(110, 'Midnight Boundary', '2098-01-31 22:00:00', 'Retained', '15000'),
            ], [103 => '2098-02-10', 106 => '2098-03-01', 109 => '2098-02-21']),
        ];
        $connectors['plaw']->failBaseRead = $failPlawBaseRead;
        if ($allowWrites) {
            $transaction = $this->createMock(\PDO::class);
            $transaction->expects($this->exactly(2))->method('beginTransaction')->willReturn(true);
            $transaction->expects($this->exactly(2))->method('commit')->willReturn(true);
            foreach ($connectors as $connector) $connector->fixtureTransaction = $transaction;
        }
        $command = new class($connectors, $allowWrites) extends GenerateRetentionCommissionReport {
            public array $connectorCalls = [];
            public int $emails = 0;
            public int $snapshotCopies = 0;

            public function __construct(private array $connectors, private bool $allowWrites) { parent::__construct(); }

            protected function initSnowflake(string $source): DBConnector
            {
                $this->connectorCalls[] = ['snowflake', $source];
                return $this->connectors[$source];
            }

            protected function initSqlServer(string $source): DBConnector
            {
                $this->connectorCalls[] = ['sql', $source];
                return $this->connectors[$source];
            }

            protected function sendReport(array $files, string $display, array $unassigned = [], bool $rosterUnavailable = false): void
            {
                $this->emails++;
            }

            protected function copySnapshotFile(string $source, string $destination): bool
            {
                $this->snapshotCopies++;
                return $this->allowWrites && parent::copySnapshotFile($source, $destination);
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $input = new ArrayInput($arguments + ['source' => 'both', 'period' => self::PERIOD], $command->getDefinition());
        $code = $command->run($input, $output);
        return [$code, $output->fetch(), $command, $connectors];
    }

    private function row(int $id, string $agent, string $cancel, ?string $outcome, string $debt, ?string $retained = null, ?string $dropped = null): array
    {
        return [
            'ID' => (string) $id, 'CLIENT' => 'Fixture ' . $id, 'RETENTION_AGENT' => $agent,
            'RETENTION_DATE' => $retained, 'IMMEDIATE_RESULTS' => $outcome, 'ENROLLED_DEBT' => $debt,
            'DROPPED_DATE' => $dropped, 'CANCEL_REQUEST_DATE' => $cancel,
        ];
    }
}

final class RetentionShadowTestConnector extends DBConnector
{
    public bool $failBaseRead = false;
    public array $sqlReads = [];
    public array $unexpectedOperations = [];
    public ?\PDO $fixtureTransaction = null;

    public function __construct(private array $rows, private array $payments) {}

    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $query = ltrim($sql);
        if (str_starts_with($query, 'WITH relevant_contacts AS')) {
            if ($this->failBaseRead) {
                return ['success' => false, 'error' => 'Synthetic source failure.'];
            }
            return ['data' => $this->rows];
        }
        if (str_starts_with($query, 'SELECT cs.CONTACT_ID')) {
            return ['data' => []];
        }
        if (str_starts_with($query, 'SELECT CONTACT_ID, TO_VARCHAR(CLEARED_DATE)')) {
            $rows = [];
            foreach ($this->payments as $id => $date) {
                $rows[] = ['CONTACT_ID' => (string) $id, 'CLEARED_DATE' => $date];
            }
            return ['data' => $rows];
        }
        $this->unexpectedOperations[] = $sql;
        throw new \LogicException('Unexpected Snowflake operation.');
    }

    public function querySqlServer(string $sql, array $params = []): array
    {
        if (str_starts_with($sql, 'SELECT Agent FROM dbo.TblCommissionRoster')) {
            // Keep most employees outside the roster to exercise the leadership cohort independently.
            $data = [['Agent' => $params[1] === 'ldr' ? 'LDR Same Day' : 'PLAW Earlier Day']];
        } elseif (str_starts_with($sql, 'SELECT Employee_Name, Location, Company FROM TblEmployees')) {
            $data = [
                ['Employee_Name' => 'LDR Same Day', 'Location' => 'A', 'Company' => 'Liberty Debt Relief'],
                ['Employee_Name' => 'PLAW Earlier Day', 'Location' => 'B', 'Company' => 'Progress Law'],
            ];
        } elseif (str_starts_with($sql, 'SELECT Period_Start, Agent, Tier') && str_contains($sql, 'TblRetentionCommissionTiers')) {
            $data = [['Period_Start' => '2098-01-01', 'Agent' => 'Outside Month Paid', 'Tier' => 1]];
        } elseif (str_starts_with($sql, 'SELECT e.LLG_ID, e.Tranche, ts.Payment_Date')) {
            $data = [];
            $payments = [
                101 => '2097-12-03', 102 => '2097-12-03', 103 => '2097-12-06',
                104 => '2097-12-30', 108 => '2097-12-30', 110 => '2097-12-02',
            ];
            foreach ($payments as $id => $date) {
                $llg = 'LLG-' . $id;
                if (str_contains($sql, "'" . $llg . "'")) {
                    $data[] = ['LLG_ID' => $llg, 'Tranche' => in_array($id, [101, 103], true) ? 53 : 'Trial tranche', 'Payment_Date' => $date];
                }
            }
        } elseif ($this->fixtureTransaction !== null && preg_match('/^\s*(?:IF|UPDATE|MERGE)\b/i', $sql)
            && (str_contains($sql, 'TblCommissionReviewResults') || str_contains($sql, 'TblRetentionCommissionTiers'))) {
            return ['success' => true, 'data' => [], 'row_count' => 1];
        } else {
            $this->unexpectedOperations[] = $sql;
            throw new \LogicException('Unexpected Azure SQL operation.');
        }
        $this->sqlReads[] = $sql;
        return ['success' => true, 'data' => $data];
    }

    public function getSqlServerConnection(): \PDO
    {
        if ($this->fixtureTransaction !== null) return $this->fixtureTransaction;
        $this->unexpectedOperations[] = 'Direct SQL transaction connection';
        throw new \LogicException('Shadow command attempted to open an Azure SQL transaction.');
    }
}
