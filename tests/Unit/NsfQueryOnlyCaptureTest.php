<?php
declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ExportNsfShadow;
use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class NsfQueryOnlyCaptureTest extends TestCase
{
    private const PERIOD = '2098-01-01';
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROSTERED = 'A Synthetic Agent';
    private const ZERO = 'B Zero Agent';
    private const UNASSIGNED = 'C Unassigned Agent';
    private const PLACEHOLDER = 'Others';

    private string $root;
    private Container $app;
    private Container $previous;
    private mixed $previousLog;
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nsf-query-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/app/commission-snapshots/2098-01/nsf', 0700, true);
        file_put_contents($this->root . '/app/commission-snapshots/2098-01/nsf/prior.xlsx', 'original');
        $this->previous = Container::getInstance();
        $this->previousLog = Log::getFacadeRoot();
        $this->app = new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . ($path === '' ? '' : '/' . $path); }
            public function runningUnitTests(): bool { return true; }
        };
        Container::setInstance($this->app);
        Log::swap(new NullLogger);
        foreach (['PAYROLL_NSF_QUERY_ONLY' => '1', 'PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT' => '1', 'PAYROLL_COMMISSION_RUN_ID' => self::RUN_ID] as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Container::setInstance($this->previous);
        if ($this->previousLog === null) {
            Log::clearResolvedInstance('log');
        } else {
            Log::swap($this->previousLog);
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0600);
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function test_actual_command_queries_only_and_preserves_canonical_snapshot(): void
    {
        $sql = new RecordingSqlServerConnector;
        [$code, $output] = $this->runNsf($sql, [], ['--no-email' => true]);

        self::assertSame(0, $code, $output);
        $this->assertQueryOnlySideEffects($sql);
        foreach ($this->archivedArtifacts() as $artifact) {
            self::assertTrue($artifact['context']['queryOnly']);
            self::assertSame('nsf', $artifact['context']['commissionResults']['reportKind']);
            self::assertArrayNotHasKey('nsfStructuredCapture', $artifact['context']);
        }
        self::assertSame([1, ''], $this->runExport());
    }

    public function test_structured_shadow_matches_legacy_employee_evidence_and_leadership(): void
    {
        $sql = new RecordingSqlServerConnector([
            '/FROM dbo\.TblCommissionRoster/i' => ['success' => true, 'data' => [['Agent' => self::ROSTERED], ['Agent' => self::ZERO]]],
        ]);
        $sourceRows = ['ldr' => [], 'plaw' => []];
        foreach (['ldr' => 1000, 'plaw' => 2000] as $source => $startId) {
            for ($index = 1; $index <= 100; $index++) {
                $sourceRows[$source][] = $this->sourceRow($startId + $index, self::ROSTERED, '2098-02-05');
            }
            $sourceRows[$source][] = $this->sourceRow($startId + 101, self::ROSTERED, null);
            $sourceRows[$source][] = $this->sourceRow($startId + 102, self::UNASSIGNED, '2098-01-03');
            $sourceRows[$source][] = ['NSF_ACTION' => '<info>Résumé / Ω</info>']
                + $this->sourceRow($startId + 103, self::PLACEHOLDER, '2098-01-03');
        }

        [$code, $output] = $this->runNsf($sql, $sourceRows, ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        $this->assertQueryOnlySideEffects($sql);

        $artifacts = $this->archivedArtifacts();
        $leadershipRows = [];
        $leadershipRoster = [];
        $readinessIds = [];
        $leader = new GenerateRetentionManagerCommission;
        $leadershipAgentKey = new \ReflectionMethod($leader, 'anthonyAgentKey');
        foreach (['ldr' => 'LDR', 'plaw' => 'Progress Law'] as $source => $display) {
            $artifact = $artifacts['nsf_' . $source];
            $capture = $artifact['context']['nsfStructuredCapture'];
            self::assertSame(1, $capture['version']);
            self::assertSame(self::RUN_ID, $capture['runId']);
            self::assertSame($source, $capture['source']);
            self::assertSame(self::PERIOD, $capture['period']);
            self::assertSame('managed', $capture['rosterBasis']);
            self::assertSame($source, $capture['readiness']['source']);
            self::assertSame(self::PERIOD, $capture['readiness']['period']);

            $legacyReadiness = GenerateRetentionManagerCommission::payrollNsfReadinessEvidence($artifact['path'], $source, self::PERIOD);
            self::assertSame($legacyReadiness['runId'], $capture['readiness']['runId']);
            self::assertSame($legacyReadiness['status'] === 'PROVISIONAL', $capture['readiness']['isProvisional']);
            $readinessIds[$source] = $capture['readiness']['runId'];
            self::assertNotSame(self::RUN_ID, $readinessIds[$source]);

            $agents = array_column($capture['agents'], null, 'agent');
            self::assertCount(4, $agents);
            self::assertTrue($agents[self::ROSTERED]['onRoster']);
            self::assertSame(100, $agents[self::ROSTERED]['clears']);
            self::assertSame(101, $agents[self::ROSTERED]['assignments']);
            self::assertSame(101, $agents[self::ROSTERED]['actions']);
            self::assertEquals(1.0, $agents[self::ROSTERED]['ratio']);
            self::assertSame(3, $agents[self::ROSTERED]['actionsTier']);
            self::assertSame(2, $agents[self::ROSTERED]['clearedTier']);
            self::assertSame(300, $agents[self::ROSTERED]['rateCents']);
            self::assertSame(30000, $agents[self::ROSTERED]['commissionCents']);
            self::assertTrue($agents[self::ZERO]['onRoster']);
            self::assertSame(0, $agents[self::ZERO]['commissionCents']);
            self::assertFalse($agents[self::UNASSIGNED]['onRoster']);
            self::assertSame(200, $agents[self::UNASSIGNED]['commissionCents']);
            self::assertFalse($agents[self::PLACEHOLDER]['onRoster']);
            self::assertSame(200, $agents[self::PLACEHOLDER]['commissionCents']);
            self::assertSame([['agent' => self::UNASSIGNED, 'commissionCents' => 200]], $capture['unassigned']);
            $this->assertWorkbookMetadataAndUnassigned($artifact['path'], $capture);
            foreach ($capture['agents'] as $agent) {
                if ($agent['onRoster']) {
                    $leadershipRoster[$leadershipAgentKey->invoke($leader, $agent['agent'])] = $agent['agent'];
                }
            }

            $legacyEmployee = CustomerServiceCalculationSnapshot::forArchivedAgent($artifacts, self::PERIOD, 'nsf', strtolower(self::ROSTERED), $source)['components'][0];
            $contacts = array_values(array_filter($capture['contacts'], fn (array $contact): bool => $contact['agent'] === self::ROSTERED && $contact['eligible']));
            self::assertSame('verified', $legacyEmployee['status']);
            self::assertSame($agents[self::ROSTERED]['commissionCents'], (int) round($legacyEmployee['amount'] * 100));
            self::assertSame(array_column($legacyEmployee['lines'], 'clientId'), array_column($contacts, 'contactId'));
            self::assertSame(array_column($legacyEmployee['lines'], 'paymentDate'), array_column($contacts, 'clearedDate'));
            foreach ($legacyEmployee['lines'] as $line) {
                self::assertSame($agents[self::ROSTERED]['rateCents'], (int) round($line['rate'] * 100));
            }
            self::assertCount(103, $capture['contacts']);
            $ineligible = array_values(array_filter($capture['contacts'], static fn (array $contact): bool => !$contact['eligible']));
            self::assertCount(1, $ineligible);
            self::assertSame(self::ROSTERED, $ineligible[0]['agent']);
            self::assertNull($ineligible[0]['clearedDate']);
            self::assertSame('2098-01-01', $ineligible[0]['returnedDate']);
            self::assertSame('2098-01-02', $ineligible[0]['recoupDate']);

            foreach ($capture['contacts'] as $contact) {
                $leadershipRows[] = ['AGENT' => $contact['agent'], 'NSF_ACTION' => $contact['action'],
                    'VALID_COMMISSION' => $contact['eligible'], 'SOURCE' => $display];
            }
        }
        self::assertNotSame($readinessIds['ldr'], $readinessIds['plaw']);
        $legacyLeadership = $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts);
        (new \ReflectionProperty($leader, 'anthonyRosterCache'))->setValue($leader, array_values($leadershipRoster));
        (new \ReflectionProperty($leader, 'payrollFrozenNsfValidity'))->setValue($leader, true);
        $structuredLeadership = (new \ReflectionMethod($leader, 'buildAnthonyPayrollSnapshot'))->invoke($leader, $leadershipRows);
        $structuredLeadership += ['nsfReadinessRunIds' => $readinessIds, 'periodStart' => self::PERIOD, 'periodEnd' => '2098-01-31'];
        foreach (['team', 'lines'] as $field) {
            $byEmployee = static fn (array $a, array $b): int => strcmp($a['employeeName'], $b['employeeName']);
            usort($legacyLeadership[$field], $byEmployee);
            usort($structuredLeadership[$field], $byEmployee);
        }
        self::assertSame($legacyLeadership, $structuredLeadership);

        [$exportCode, $stdout] = $this->runExport();
        self::assertSame(0, $exportCode);
        self::assertCount(1, preg_split('/\R/', trim($stdout)));
        self::assertStringContainsString('<info>Résumé / Ω</info>', $stdout);
        self::assertStringNotContainsString($this->root, $stdout);
        $envelope = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['version', 'runId', 'period', 'sources'], array_keys($envelope));
        self::assertSame(1, $envelope['version']);
        self::assertSame(self::RUN_ID, $envelope['runId']);
        self::assertSame(self::PERIOD, $envelope['period']);
        self::assertSame(['ldr', 'plaw'], array_keys($envelope['sources']));
        foreach ($envelope['sources'] as $source => $entry) {
            $artifact = $artifacts['nsf_' . $source];
            self::assertSame(['workbookSha256', 'captureJson', 'captureSha256'], array_keys($entry));
            self::assertSame($artifact['sha256'], $entry['workbookSha256']);
            self::assertSame(hash('sha256', $entry['captureJson']), $entry['captureSha256']);
            self::assertSame($artifact['context']['nsfStructuredCapture'], json_decode($entry['captureJson'], true, flags: JSON_THROW_ON_ERROR));
        }
        self::assertSame($artifacts, $this->archivedArtifacts());
    }

    public function test_export_fails_silently_if_the_second_brand_is_missing_or_changed(): void
    {
        [$code, $output] = $this->runNsf(new RecordingSqlServerConnector, [], ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        self::assertSame(0, $this->runExport()[0]);
        self::assertSame([1, ''], $this->runExport('2098-01-02'));
        self::assertSame([1, ''], $this->runExport(self::PERIOD, 'invalid'));

        ['nsf_ldr' => $ldr, 'nsf_plaw' => $plaw] = $this->archivedArtifacts();
        $metadataPath = dirname($plaw['path']) . '/artifact.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $context = $metadata['context'];
        $wrongContexts = [
            'not query-only' => ['queryOnly' => 1] + $context,
            'missing capture' => ['nsfStructuredCapture' => null] + $context,
        ];
        $capture = $context['nsfStructuredCapture'];
        foreach ([
            'unknown version' => ['version' => 2],
            'wrong source' => ['source' => 'ldr'],
            'wrong month' => ['period' => '2098-02-01'],
            'wrong outer run' => ['runId' => str_repeat('b', 32)],
            'missing result rows' => ['agents' => null],
        ] as $case => $override) {
            $wrongContexts[$case] = ['nsfStructuredCapture' => $override + $capture] + $context;
        }
        foreach ([
            'wrong readiness source' => ['source' => 'ldr'],
            'wrong readiness month' => ['period' => '2098-02-01'],
            'invalid readiness ID' => ['runId' => 'invalid'],
            'outer ID reused for readiness' => ['runId' => self::RUN_ID],
            'other brand readiness ID reused' => ['runId' => $ldr['context']['nsfStructuredCapture']['readiness']['runId']],
            'incorrect provisional status' => ['isProvisional' => !$capture['readiness']['isProvisional']],
        ] as $case => $override) {
            $wrongCapture = ['readiness' => $override + $capture['readiness']] + $capture;
            $wrongContexts[$case] = ['nsfStructuredCapture' => $wrongCapture] + $context;
        }
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        foreach ($wrongContexts as $case => $wrongContext) {
            $wrongMetadata = ['context' => $wrongContext, 'contextSha256' => hash('sha256', json_encode($wrongContext, $jsonFlags))] + $metadata;
            file_put_contents($metadataPath, json_encode($wrongMetadata, $jsonFlags));
            self::assertSame([1, ''], $this->runExport(), $case);
        }

        file_put_contents($metadataPath, json_encode($metadata, $jsonFlags));
        file_put_contents($plaw['path'], 'workbook changed', FILE_APPEND);
        self::assertSame([1, ''], $this->runExport(), 'changed workbook bytes');
        unlink($metadataPath);
        self::assertSame([1, ''], $this->runExport(), 'missing brand');
    }

    public static function invalidShadowModes(): array
    {
        return [
            'query mode disabled' => ['PAYROLL_NSF_QUERY_ONLY=0', ['--no-email' => true]],
            'capture disabled' => ['PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=0', ['--no-email' => true]],
            'email still enabled' => ['PAYROLL_NSF_QUERY_ONLY=1', []],
        ];
    }

    public function test_automation_cannot_capture_an_export_in_its_log(): void
    {
        [$code, $output] = $this->runNsf(new RecordingSqlServerConnector, [], ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');
        $artisan->add(new ExportNsfShadow);
        self::assertSame(1, $artisan->call('payroll:export-nsf-shadow ' . self::PERIOD . ' ' . self::RUN_ID));
        self::assertSame('', $artisan->output());
        $log = new BufferedOutput;
        $input = new ArgvInput(['artisan', 'payroll:export-nsf-shadow', self::PERIOD, self::RUN_ID]);
        self::assertSame(1, $artisan->run($input, $log));
        self::assertSame('', $log->fetch());
    }

    #[DataProvider('invalidShadowModes')]
    public function test_structured_shadow_rejects_incomplete_opt_in_before_connectors(string $environment, array $options): void
    {
        putenv($environment);
        $sql = new RecordingSqlServerConnector;
        [$code, , $connectorCalls] = $this->runNsf($sql, [], $options + ['--structured-shadow' => true]);

        self::assertSame(1, $code);
        self::assertSame([], $connectorCalls);
        self::assertSame([], $sql->calls);
        self::assertDirectoryDoesNotExist($this->root . '/app/payroll-report-artifacts');
    }

    private function sourceRow(int $id, string $agent, ?string $clearedDate): array
    {
        return ['ID' => (string) $id, 'AGENT' => $agent, 'NSF_RETURNED_DATE' => '2098-01-01',
            'NSF_ACTION' => 'Resubmitted', 'NSF_RECOUP_DATE' => '2098-01-02', 'CLEARED_DATE' => $clearedDate];
    }

    private function assertWorkbookMetadataAndUnassigned(string $path, array $capture): void
    {
        $book = IOFactory::load($path);
        try {
            $properties = $book->getProperties();
            self::assertSame($properties->getCustomPropertyValue('NSF Run Started (UTC)'), $capture['readiness']['startedAt']);
            self::assertSame($properties->getCustomPropertyValue('NSF Final Runs After (UTC)'), $capture['readiness']['finalAfter']);
            self::assertSame($properties->getCustomPropertyValue('NSF Payment Cutoff (Pacific)'), $capture['readiness']['cutoffPacific']);
            $summary = $book->getSheetByName('Agent Summary');
            self::assertNotNull($summary);
            self::assertSame('Unassigned Agents', $summary->getCell('A5')->getValue());
            self::assertSame(6, $summary->getHighestDataRow('A'));
            self::assertSame($capture['unassigned'][0]['agent'], $summary->getCell('A6')->getValue());
            self::assertSame($capture['unassigned'][0]['commissionCents'], (int) round($summary->getCell('I6')->getValue() * 100));
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function runNsf(RecordingSqlServerConnector $sql, array $sourceRows, array $options): array
    {
        $command = new class($sql, $sourceRows) extends GenerateNSFCommissionReport {
            public array $connectorCalls = [];
            private string $source;

            public function __construct(private DBConnector $sql, private array $sourceRows) { parent::__construct(); }
            protected function initSnowflake(string $source): DBConnector
            {
                $this->connectorCalls[] = $source;
                $this->source = $source;
                return $this->sql;
            }
            protected function initSqlServer(string $source): DBConnector { return $this->sql; }
            protected function fetchNSFRows(DBConnector $sf, array $cfg, string $startDate, string $endDate): array
            {
                return $this->sourceRows[$this->source] ?? [];
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput(['source' => 'both', 'period' => self::PERIOD] + $options, $command->getDefinition()), $output);
        return [$code, $output->fetch(), $command->connectorCalls];
    }

    private function assertQueryOnlySideEffects(RecordingSqlServerConnector $sql): void
    {
        self::assertNotEmpty($sql->calls);
        foreach ($sql->calls as $call) {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $call['sql']);
        }
        self::assertSame('original', file_get_contents($this->root . '/app/commission-snapshots/2098-01/nsf/prior.xlsx'));
        $temp = $this->root . '/app/payroll-nsf-query-tmp/' . self::RUN_ID;
        self::assertSame([], is_dir($temp) ? array_diff(scandir($temp), ['.', '..']) : []);
    }

    private function archivedArtifacts(): array
    {
        $archive = new PayrollReportArtifactArchive;
        return [
            'nsf_ldr' => $archive->read('nsf_ldr', self::PERIOD, self::RUN_ID),
            'nsf_plaw' => $archive->read('nsf_plaw', self::PERIOD, self::RUN_ID),
        ];
    }

    private function runExport(string $period = self::PERIOD, string $runId = self::RUN_ID): array
    {
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');
        $artisan->add(new ExportNsfShadow);
        $output = new class(OutputInterface::VERBOSITY_QUIET, true) extends ConsoleOutput {
            public string $captured = '';
            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured .= $message . ($newline ? PHP_EOL : '');
            }
        };
        $code = $artisan->run(new ArgvInput(['artisan', 'payroll:export-nsf-shadow', $period, $runId]), $output);

        return [$code, $output->captured];
    }
}
