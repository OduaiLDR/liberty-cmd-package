<?php
declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ExportNsfShadow;
use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Console\Commands\ReadNsfTeamLeaderShadow;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\NsfStructuredCapture;
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
    private const PLAW_ROSTERED = 'A SYNTHETIC AGENT';
    private const LDR_ZERO = 'B LDR Zero Agent';
    private const PLAW_ZERO = 'B Progress Law Zero Agent';
    private const UNASSIGNED = 'C Unassigned Agent';
    private const PLACEHOLDER = 'Others';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

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
        foreach (['PAYROLL_NSF_QUERY_ONLY' => '1', 'PAYROLL_NSF_STRUCTURED_CAPTURE' => '0',
            'PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT' => '1', 'PAYROLL_COMMISSION_RUN_ID' => self::RUN_ID] as $key => $value) {
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
        $rosters = [
            'ldr' => [self::ROSTERED, self::LDR_ZERO],
            'plaw' => [self::PLAW_ROSTERED, self::PLAW_ZERO],
        ];
        $sql = new RecordingSqlServerConnector([
            '/FROM dbo\.TblCommissionRoster/i' => static fn (string $sql, array $params): array => [
                'success' => true,
                'data' => array_map(static fn (string $agent): array => ['Agent' => $agent], $rosters[$params[1]]),
            ],
            '/FROM TblEmployees/i' => ['success' => true, 'data' => [
                ['Employee_Name' => self::LDR_ZERO, 'Location' => 'A'],
                ['Employee_Name' => self::PLAW_ZERO, 'Location' => 'B'],
                ['Employee_Name' => self::ROSTERED, 'Location' => 'Z'],
            ]],
        ]);
        $sourceRows = ['ldr' => [], 'plaw' => []];
        foreach ($rosters as $source => [$rosteredAgent]) {
            $startId = $source === 'ldr' ? 1000 : 2000;
            for ($index = 1; $index <= 100; $index++) {
                $contactId = $index === 1 ? 10 : $startId + $index;
                $sourceRows[$source][] = $this->sourceRow($contactId, $rosteredAgent, '2098-02-05');
            }
            $sourceRows[$source][] = ['NSF_ACTION' => ''] + $this->sourceRow($startId + 101, $rosteredAgent, null);
            $sourceRows[$source][] = $this->sourceRow($startId + 102, self::UNASSIGNED, '2098-01-03');
            $sourceRows[$source][] = ['NSF_ACTION' => '<info>Résumé / Ω</info>']
                + $this->sourceRow($startId + 103, self::PLACEHOLDER, '2098-01-03');
        }

        [$code, $output] = $this->runNsf($sql, $sourceRows, ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        $this->assertQueryOnlySideEffects($sql);

        $artifacts = $this->archivedArtifacts();
        $readinessIds = [];
        $leader = new GenerateRetentionManagerCommission;
        foreach ($rosters as $source => [$rosteredAgent, $zeroAgent]) {
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
            self::assertTrue($agents[$rosteredAgent]['onRoster']);
            self::assertSame(100, $agents[$rosteredAgent]['clears']);
            self::assertSame(101, $agents[$rosteredAgent]['assignments']);
            self::assertSame(100, $agents[$rosteredAgent]['actions']);
            self::assertSame(100 / 101, $agents[$rosteredAgent]['ratio']);
            self::assertSame(3, $agents[$rosteredAgent]['actionsTier']);
            self::assertSame(2, $agents[$rosteredAgent]['clearedTier']);
            self::assertSame(300, $agents[$rosteredAgent]['rateCents']);
            self::assertSame(30000, $agents[$rosteredAgent]['commissionCents']);
            self::assertTrue($agents[$zeroAgent]['onRoster']);
            self::assertSame(0, $agents[$zeroAgent]['commissionCents']);
            self::assertFalse($agents[self::UNASSIGNED]['onRoster']);
            self::assertSame(200, $agents[self::UNASSIGNED]['commissionCents']);
            self::assertFalse($agents[self::PLACEHOLDER]['onRoster']);
            self::assertSame(200, $agents[self::PLACEHOLDER]['commissionCents']);
            self::assertSame([['agent' => self::UNASSIGNED, 'commissionCents' => 200]], $capture['unassigned']);
            $this->assertWorkbookMetadataAndUnassigned($artifact['path'], $capture);

            $legacyEmployee = CustomerServiceCalculationSnapshot::forArchivedAgent($artifacts, self::PERIOD, 'nsf', strtolower($rosteredAgent), $source)['components'][0];
            $contacts = array_values(array_filter($capture['contacts'], static fn (array $contact): bool => $contact['agent'] === $rosteredAgent && $contact['eligible']));
            self::assertSame('verified', $legacyEmployee['status']);
            self::assertSame($agents[$rosteredAgent]['commissionCents'], (int) round($legacyEmployee['amount'] * 100));
            self::assertSame(array_column($legacyEmployee['lines'], 'clientId'), array_column($contacts, 'contactId'));
            self::assertSame(array_column($legacyEmployee['lines'], 'paymentDate'), array_column($contacts, 'clearedDate'));
            foreach ($legacyEmployee['lines'] as $line) {
                self::assertSame($agents[$rosteredAgent]['rateCents'], (int) round($line['rate'] * 100));
            }
            self::assertCount(103, $capture['contacts']);
            $ineligible = array_values(array_filter($capture['contacts'], static fn (array $contact): bool => !$contact['eligible']));
            self::assertCount(1, $ineligible);
            self::assertSame($rosteredAgent, $ineligible[0]['agent']);
            self::assertNull($ineligible[0]['clearedDate']);
            self::assertSame('2098-01-01', $ineligible[0]['returnedDate']);
            self::assertSame('2098-01-02', $ineligible[0]['recoupDate']);
        }
        self::assertNotSame($readinessIds['ldr'], $readinessIds['plaw']);
        $legacyLeadership = $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts, false);
        self::assertSame($readinessIds, $legacyLeadership['nsfReadinessRunIds']);
        self::assertSame([self::LDR_ZERO, self::PLAW_ROSTERED, self::PLAW_ZERO], array_column($legacyLeadership['team'], 'employeeName'));
        self::assertSame([0, 200, 0], array_column($legacyLeadership['team'], 'clearCount'));
        self::assertSame([0, 200, 0], array_column($legacyLeadership['team'], 'actionCount'));
        self::assertSame([0, 202, 0], array_column($legacyLeadership['team'], 'assignedCount'));
        self::assertSame('LDR / Progress Law', $legacyLeadership['team'][1]['source']);
        self::assertSame($legacyLeadership['team'], $legacyLeadership['lines']);
        self::assertSame(0.40, $legacyLeadership['summary']['commissionRate']);
        self::assertSame(80.0, $legacyLeadership['summary']['totalPay']);
        $amendmentInputs = array_map(static fn (array $artifact): array => [
            'path' => $artifact['path'], 'context' => $artifact['context'],
        ], $artifacts);
        $legacyBundle = $leader->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-01-31',
            $amendmentInputs, $this->root . '/legacy-leader', null, true);

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
        foreach ($artifacts as $artifact) {
            self::assertTrue(chmod($artifact['path'], 0600));
            self::assertTrue(unlink($artifact['path']));
            self::assertFileDoesNotExist($artifact['path']);
        }
        self::assertSame([1, ''], $this->runExport());
        self::assertSame($legacyLeadership, $leader->buildPayrollReviewNsfStructuredSnapshot(self::PERIOD, self::RUN_ID, $envelope));
        self::assertSame($legacyLeadership, $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts));
        $structuredBundle = $leader->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-01-31',
            $artifacts, $this->root . '/structured-leader', null, true);
        self::assertSame($legacyBundle['reports'], $structuredBundle['reports']);
        $leaderWorkbook = IOFactory::load($structuredBundle['files']['nsf_team_leader']['path']);
        try {
            self::assertSame(80.0, (float) $leaderWorkbook->getSheetByName('Commission Summary')->getCell('B8')->getValue());
        } finally {
            $leaderWorkbook->disconnectWorksheets();
        }
        $tampered = $artifacts;
        $tampered['nsf_plaw']['context']['nsfStructuredCapture']['contacts'][0]['eligible'] = false;
        try {
            $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $tampered);
            self::fail('A changed structured leadership capture was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('fingerprint', $error->getMessage());
        }

        [$readerCode, $readerStdout, $readerStderr] = $this->runTeamLeaderReader($stdout);
        self::assertSame(0, $readerCode, $readerStderr);
        self::assertSame('', $readerStderr);
        self::assertCount(1, preg_split('/\R/', trim($readerStdout)));
        self::assertStringNotContainsString($this->root, $readerStdout);
        self::assertSame($legacyLeadership, json_decode($readerStdout, true, flags: JSON_THROW_ON_ERROR));

        $envelope = $this->changeCapture($envelope, 'ldr', static function (array $capture): array {
            $capture['contacts'][0]['clearedDate'] = '2098-02-06';
            return $capture;
        });
        self::assertSame($legacyLeadership, $leader->buildPayrollReviewNsfStructuredSnapshot(self::PERIOD, self::RUN_ID, $envelope));
    }

    public function test_structured_leadership_preserves_both_fallback_rosters_on_an_empty_month(): void
    {
        $sql = new RecordingSqlServerConnector;
        [$code, $output] = $this->runNsf($sql, [], ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        [$exportCode, $stdout] = $this->runExport();
        self::assertSame(0, $exportCode);

        $artifacts = $this->archivedArtifacts();
        $names = [];
        foreach ($artifacts as $artifact) {
            $capture = $artifact['context']['nsfStructuredCapture'];
            self::assertSame('fallback', $capture['rosterBasis']);
            self::assertSame([], $capture['contacts']);
            self::assertNotEmpty($capture['agents']);
            self::assertSame([true], array_values(array_unique(array_column($capture['agents'], 'onRoster'))));
            $names = array_merge($names, array_column($capture['agents'], 'agent'));
        }

        $leader = new GenerateRetentionManagerCommission;
        $legacy = $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts, false);
        $envelope = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $structured = $leader->buildPayrollReviewNsfStructuredSnapshot(self::PERIOD, self::RUN_ID, $envelope);
        self::assertSame($legacy, $structured);
        self::assertEqualsCanonicalizing(array_values(array_unique($names)), array_column($structured['team'], 'employeeName'));
        self::assertSame(0, $structured['summary']['assignedCount']);
        self::assertSame(0.0, $structured['summary']['totalPay']);
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
        foreach ($wrongContexts as $case => $wrongContext) {
            $wrongMetadata = ['context' => $wrongContext, 'contextSha256' => hash('sha256', json_encode($wrongContext, self::JSON_FLAGS))] + $metadata;
            file_put_contents($metadataPath, json_encode($wrongMetadata, self::JSON_FLAGS));
            self::assertSame([1, ''], $this->runExport(), $case);
        }

        file_put_contents($metadataPath, json_encode($metadata, self::JSON_FLAGS));
        file_put_contents($plaw['path'], 'workbook changed', FILE_APPEND);
        self::assertSame([1, ''], $this->runExport(), 'changed workbook bytes');
        unlink($metadataPath);
        self::assertSame([1, ''], $this->runExport(), 'missing brand');
    }

    public function test_structured_leadership_fails_closed_on_incomplete_or_inconsistent_evidence(): void
    {
        $sql = new RecordingSqlServerConnector([
            '/FROM dbo\.TblCommissionRoster/i' => ['success' => true, 'data' => [['Agent' => self::ROSTERED]]],
        ]);
        $rows = [$this->sourceRow(10, self::ROSTERED, '2098-02-05')];
        [$code, $output] = $this->runNsf($sql, ['ldr' => $rows, 'plaw' => $rows], ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        [$exportCode, $stdout] = $this->runExport();
        self::assertSame(0, $exportCode);
        $envelope = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $leader = new GenerateRetentionManagerCommission;
        $valid = $leader->buildPayrollReviewNsfStructuredSnapshot(self::PERIOD, self::RUN_ID, $envelope);
        self::assertSame(2, $valid['summary']['clearCount']);

        $ldr = $envelope['sources']['ldr'];
        $plaw = $envelope['sources']['plaw'];
        $invalid = [
            'unsupported envelope version' => ['version' => 2] + $envelope,
            'missing second company' => ['sources' => ['ldr' => $ldr]] + $envelope,
            'foreign company' => ['sources' => ['ldr' => $ldr, 'plaw' => $plaw, 'other' => $plaw]] + $envelope,
            'sources swapped' => ['sources' => ['ldr' => $plaw, 'plaw' => $ldr]] + $envelope,
            'changed bytes' => ['sources' => ['ldr' => ['captureJson' => ' ' . $ldr['captureJson']] + $ldr, 'plaw' => $plaw]] + $envelope,
            'invalid workbook digest' => ['sources' => ['ldr' => ['workbookSha256' => 'invalid'] + $ldr, 'plaw' => $plaw]] + $envelope,
        ];
        foreach ([
            'wrong capture period' => ['period' => '2098-02-01'],
            'wrong capture run' => ['runId' => str_repeat('b', 32)],
            'unsupported capture version' => ['version' => 2],
            'contacts are not a list' => ['contacts' => ['unexpected' => []]],
        ] as $case => $change) {
            $invalid[$case] = $this->changeCapture($envelope, 'plaw', static fn (array $capture): array => $change + $capture);
        }
        foreach ([
            'wrong readiness company' => ['source' => 'ldr'],
            'wrong readiness period' => ['period' => '2098-02-01'],
            'readiness reused outer run ID' => ['runId' => self::RUN_ID],
            'two brands share one readiness run' => ['runId' => $valid['nsfReadinessRunIds']['ldr']],
            'readiness contradicts timing' => ['isProvisional' => false],
        ] as $case => $change) {
            $invalid[$case] = $this->changeCapture($envelope, 'plaw', static fn (array $capture): array => [
                'readiness' => $change + $capture['readiness'],
            ] + $capture);
        }
        $invalid['missing frozen eligibility'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            unset($capture['contacts'][0]['eligible']);
            return $capture;
        });
        $invalid['non-boolean eligibility'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['contacts'][0]['eligible'] = 'false';
            return $capture;
        });
        $invalid['duplicate contact within one company'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['contacts'][] = $capture['contacts'][0];
            return $capture;
        });
        $invalid['ambiguous company roster'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['agents'][] = ['agent' => strtoupper($capture['agents'][0]['agent'])] + $capture['agents'][0];
            return $capture;
        });
        $invalid['non-boolean roster membership'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['agents'][0]['onRoster'] = 1;
            return $capture;
        });
        $invalid['numeric workbook detail name'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['contacts'][0]['agent'] = '123';
            return $capture;
        });
        $invalid['numeric workbook roster name'] = $this->changeCapture($envelope, 'plaw', static function (array $capture): array {
            $capture['agents'][0]['agent'] = '123';
            return $capture;
        });
        foreach ($invalid as $case => $badEnvelope) {
            $this->assertRejectsTeamLeaderEvidence($leader, $case, self::PERIOD, self::RUN_ID, $badEnvelope);
        }
        $this->assertRejectsTeamLeaderEvidence($leader, 'caller selected a different period', '2098-02-01', self::RUN_ID, $envelope);
        $this->assertRejectsTeamLeaderEvidence($leader, 'caller selected a different run', self::PERIOD, str_repeat('b', 32), $envelope);
        self::assertSame($valid, $leader->buildPayrollReviewNsfStructuredSnapshot(self::PERIOD, self::RUN_ID, $envelope));

        self::assertSame([1, '', ''], $this->runTeamLeaderReader('{'));
        self::assertSame([1, '', ''], $this->runTeamLeaderReader(json_encode($invalid['missing second company'], self::JSON_FLAGS)));
        self::assertSame([1, '', ''], $this->runTeamLeaderReader($stdout, '2098-02-01'));
    }

    public static function invalidShadowModes(): array
    {
        return [
            'query mode disabled' => ['PAYROLL_NSF_QUERY_ONLY=0', ['--no-email' => true]],
            'capture disabled' => ['PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=0', ['--no-email' => true]],
            'email still enabled' => ['PAYROLL_NSF_QUERY_ONLY=1', []],
        ];
    }

    public function test_automation_cannot_capture_shadow_reports_in_its_log(): void
    {
        [$code, $output] = $this->runNsf(new RecordingSqlServerConnector, [], ['--no-email' => true, '--structured-shadow' => true]);
        self::assertSame(0, $code, $output);
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');
        $artisan->add(new ExportNsfShadow);
        $artisan->add(new ReadNsfTeamLeaderShadow);
        foreach (['payroll:export-nsf-shadow', 'payroll:read-nsf-team-leader-shadow'] as $command) {
            self::assertSame(1, $artisan->call($command . ' ' . self::PERIOD . ' ' . self::RUN_ID));
            self::assertSame('', $artisan->output());
            $log = new BufferedOutput;
            $input = new ArgvInput(['artisan', $command, self::PERIOD, self::RUN_ID]);
            self::assertSame(1, $artisan->run($input, $log));
            self::assertSame('', $log->fetch());
        }
    }

    public function test_normal_runner_capture_freezes_both_sources_without_changing_the_writer(): void
    {
        putenv('PAYROLL_NSF_QUERY_ONLY=0');
        putenv('PAYROLL_NSF_STRUCTURED_CAPTURE=1');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('columnCount')->willReturn(1);
        $statement->method('fetchColumn')->willReturn(0);
        $connection = $this->createMock(\PDO::class);
        $connection->method('prepare')->willReturn($statement);
        $connection->expects($this->exactly(2))->method('beginTransaction')->willReturn(true);
        $connection->expects($this->exactly(2))->method('commit')->willReturn(true);
        $sql = new class($connection) extends DBConnector {
            public array $readiness = [];
            public array $sourceResets = [];

            public function __construct(private \PDO $connection) {}
            public function getSqlServerConnection(): \PDO { return $this->connection; }

            public function querySqlServer(string $sql, array $params = []): array
            {
                $data = [];
                if (str_starts_with($sql, 'MERGE dbo.TblNsfCommissionReadiness')) {
                    $this->readiness[$params[0]] = ['Run_Id' => $params[2], 'Status' => 'running', 'Completed_At' => null];
                } elseif (str_starts_with($sql, 'UPDATE dbo.TblNsfCommissionReadiness')) {
                    $this->readiness[$params[0]]['Status'] = 'completed';
                    $this->readiness[$params[0]]['Completed_At'] = 'fixture-completed';
                } elseif (str_starts_with($sql, 'SELECT Source,Period_Start,Status')) {
                    $data = [$this->readiness[$params[0]]];
                } elseif (str_starts_with($sql, 'UPDATE dbo.TblCommissionReviewResults')) {
                    $this->sourceResets[] = $params;
                } elseif (str_starts_with($sql, 'SELECT Agent FROM')) {
                    $data = [['Agent' => self::AGENT]];
                }
                return ['success' => true, 'data' => $data, 'row_count' => 1];
            }

            private const AGENT = 'A Synthetic Agent';
        };
        [$code, $output] = $this->runNsf($sql, [
            'ldr' => [$this->sourceRow(101, self::ROSTERED, '2098-02-05')],
            'plaw' => [$this->sourceRow(201, self::ROSTERED, '2098-02-05')],
        ], ['--no-email' => true]);
        self::assertSame(0, $code, $output);
        self::assertSame([['nsf', 'ldr', self::PERIOD], ['nsf', 'plaw', self::PERIOD]], $sql->sourceResets);

        $artifacts = $this->archivedArtifacts();
        $envelope = json_decode(NsfStructuredCapture::export($artifacts, self::PERIOD, self::RUN_ID, false), true, flags: JSON_THROW_ON_ERROR);
        foreach ($artifacts as $key => $artifact) {
            $source = substr($key, 4);
            self::assertArrayNotHasKey('queryOnly', $artifact['context']);
            self::assertSame('completed', $sql->readiness[$source]['Status']);
            self::assertSame($artifact['sha256'], $envelope['sources'][$source]['workbookSha256']);
            self::assertSame($artifact['context']['nsfStructuredCapture'],
                json_decode($envelope['sources'][$source]['captureJson'], true, flags: JSON_THROW_ON_ERROR));
        }
        [$exit, $stdout] = $this->runExport();
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);

        $leader = new GenerateRetentionManagerCommission;
        $wanted = $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts, false);
        foreach ($artifacts as &$artifact) {
            unset($artifact['path']);
        }
        unset($artifact);
        self::assertSame($wanted, $leader->buildPayrollReviewLeadershipSnapshot('nsf_team_leader', self::PERIOD, '2098-01-31', $artifacts));
        $capture = &$artifacts['nsf_ldr']['context']['nsfStructuredCapture'];
        $capture['contacts'][] = [
            'contactId' => '999', 'agent' => null, 'returnedDate' => self::PERIOD,
            'action' => null, 'recoupDate' => null, 'clearedDate' => null, 'eligible' => false,
        ];
        unset($capture);
        $artifacts['nsf_ldr']['contextSha256'] = hash('sha256', json_encode($artifacts['nsf_ldr']['context'], self::JSON_FLAGS));
        $built = $leader->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-01-31', $artifacts, $this->root . '/normal-leader', null, true);
        self::assertSame($wanted['summary'], $built['reports']['nsf_team_leader']['summary']);
        self::assertFileExists($built['files']['nsf_team_leader']['path']);
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

    private function runNsf(DBConnector $sql, array $sourceRows, array $options): array
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

    private function changeCapture(array $envelope, string $source, callable $change): array
    {
        $capture = json_decode($envelope['sources'][$source]['captureJson'], true, flags: JSON_THROW_ON_ERROR);
        $captureJson = json_encode($change($capture), self::JSON_FLAGS);
        $envelope['sources'][$source]['captureJson'] = $captureJson;
        $envelope['sources'][$source]['captureSha256'] = hash('sha256', $captureJson);

        return $envelope;
    }

    private function assertRejectsTeamLeaderEvidence(GenerateRetentionManagerCommission $leader, string $case, string $period, string $runId, array $envelope): void
    {
        try {
            $leader->buildPayrollReviewNsfStructuredSnapshot($period, $runId, $envelope);
            self::fail('Accepted ' . $case);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            self::assertNotSame('', $error->getMessage(), $case);
        }
    }

    private function runTeamLeaderReader(string $stdin, string $period = self::PERIOD): array
    {
        // Run a real pipe without giving the child a Laravel storage path or invoking the Runner's automation.
        $script = <<<'PHP'
            require $argv[1];
            $app = new class extends \Illuminate\Container\Container {
                public function runningUnitTests(): bool { return true; }
            };
            $artisan = new \Illuminate\Console\Application($app, new \Illuminate\Events\Dispatcher($app), 'test');
            $artisan->add(new \Cmd\Reports\Console\Commands\ReadNsfTeamLeaderShadow);
            $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'payroll:read-nsf-team-leader-shadow', $argv[2], $argv[3]]);
            exit($artisan->run($input, new \Symfony\Component\Console\Output\ConsoleOutput));
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', __DIR__ . '/../bootstrap-workspace.php', $period, self::RUN_ID],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        self::assertSame(strlen($stdin), fwrite($pipes[0], $stdin));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
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
