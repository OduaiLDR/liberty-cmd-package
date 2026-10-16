<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport\GenerateMissingAssignmentsReport;
use Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport\ReportData;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class GenerateMissingAssignmentsReportTest extends TestCase
{
    private string $storageRoot;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 16, 0, 0, 'America/Los_Angeles'));
        $this->storageRoot = sys_get_temp_dir().'/missing-assignments-tests-'.bin2hex(random_bytes(8));
        mkdir($this->storageRoot.'/app', 0777, true);
        $app = new class($this->storageRoot) extends Container {
            public function __construct(private readonly string $storageRoot) {}

            public function storagePath(string $path = ''): string
            {
                return $this->storageRoot.($path === '' ? '' : '/'.ltrim($path, '/\\'));
            }
        };
        $app->instance('log', new NullLogger);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->storageRoot.'/app');
        rmdir($this->storageRoot);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_window_starts_in_the_prior_fridays_month_and_includes_that_friday(): void
    {
        self::assertSame([
            'start' => '2026-10-01', 'end' => '2026-10-09',
            'end_exclusive' => '2026-10-10', 'report_date' => '2026-10-12',
        ], $this->window());
    }

    public function test_month_boundary_uses_the_prior_fridays_month_and_queries_its_clients(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 2, 16, 0, 0, 'America/Los_Angeles'));
        self::assertSame([
            'start' => '2026-10-01', 'end' => '2026-10-30',
            'end_exclusive' => '2026-10-31', 'report_date' => '2026-11-02',
        ], $this->window());
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->insert('TblContacts', $this->contact('LLG-1', 'First Client', '2026-10-01 00:00:00'));
        $connector->insert('TblContacts', $this->contact('LLG-2', 'Last Client', '2026-10-30 23:59:59'));
        $connector->insert('TblContacts', $this->contact('LLG-3', 'Previous Month', '2026-09-30 23:59:59'));
        $connector->insert('TblContacts', $this->contact('LLG-4', 'Saturday', '2026-10-31 00:00:00'));
        $connector->insert('TblContacts', $this->contact('LLG-5', 'Run Month', '2026-11-01 00:00:00'));
        $window = $this->window();
        $rows = (new ReportData)->fetch($connector, $window['start'], $window['end_exclusive']);
        self::assertSame(['LLG-1', 'LLG-2'], array_column($rows, 'llg_id'));
        self::assertSame(['2026-10-01', '2026-10-31'], $connector->queries[0]['params']);
    }

    public function test_year_boundary_uses_the_prior_fridays_year_and_month(): void
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 16, 0, 0, 'America/Los_Angeles'));
        self::assertSame([
            'start' => '2023-12-01', 'end' => '2023-12-29',
            'end_exclusive' => '2023-12-30', 'report_date' => '2024-01-01',
        ], $this->window());
    }

    public function test_friday_on_the_first_produces_a_single_day_window(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 1, 4, 16, 0, 0, 'America/Los_Angeles'));
        self::assertSame([
            'start' => '2027-01-01', 'end' => '2027-01-01',
            'end_exclusive' => '2027-01-02', 'report_date' => '2027-01-04',
        ], $this->window());
    }

    public function test_window_uses_the_business_calendar_even_before_utc_month_boundary(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 1, 0, 0, 'UTC'));
        self::assertSame('2026-10-01', $this->window()['start']);
        self::assertSame('2026-10-31', $this->window()['report_date']);
    }

    public function test_fixture_query_filters_and_sorts_the_ticket_population(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->insert('TblContacts', $this->contact('LLG-1', 'Zulu', '2026-10-01 00:00:00'));
        $connector->insert('TblContacts', $this->contact('LLG-2', 'Alpha', '2026-10-09 23:59:59'));
        $connector->insert('TblContacts', $this->contact('LLG-3', 'Beta', '2026-10-01 15:00:00'));
        foreach ([
            ['LLG-4', 'Created_Date', '2026-09-30 23:59:59'],
            ['LLG-5', 'Created_Date', '2026-10-10 00:00:00'],
            ['LLG-6', 'Agent', 'Assigned Agent'],
            ['LLG-7', 'Agent', null],
            ['LLG-8', 'Stage', 'Lead'],
            ['LLG-9', 'Status', 'Funded'],
        ] as [$id, $field, $value]) {
            $connector->insert('TblContacts', array_replace($this->contact($id), [$field => $value]));
        }

        $rows = (new ReportData)->fetch($connector, '2026-10-01', '2026-10-10');

        self::assertSame(['LLG-3', 'LLG-1', 'LLG-2'], array_column($rows, 'llg_id'));
        self::assertSame(['2026-10-01', '2026-10-01', '2026-10-09'], array_column($rows, 'created_date'));
        self::assertSame(['2026-10-01', '2026-10-10'], $connector->queries[0]['params']);
        self::assertSame('', $rows[0]['lending_tower_id']);
        self::assertCount(2, $connector->queries);
    }

    public function test_source_ids_require_one_physical_owner_and_matching_identity(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        for ($id = 1; $id <= 7; $id++) {
            $connector->insert('TblContacts', $this->contact('LLG-'.$id, 'Client '.$id));
        }
        $connector->insert('TblContactsLDR', $this->contact('LLG-1', 'Client 1') + ['External_ID' => '888001']);
        $connector->insert('TblContactsPLAW', $this->contact('LLG-2', 'Client 2') + ['External_ID' => '888002']);
        foreach (['TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $connector->insert($table, $this->contact('LLG-3', 'Client 3') + ['External_ID' => '888003']);
        }
        $connector->insert('TblContactsLDR', $this->contact('LLG-4', 'Different Person') + ['External_ID' => '888004']);
        $connector->insert('TblContactsLDR', $this->contact('LLG-5', 'Client 5') + ['External_ID' => '1234567840']);
        $connector->insert('TblContactsLDR', $this->contact('LLG-6', 'Client 6') + ['External_ID' => '888006']);
        $connector->insert('TblContactsLDR', $this->contact('LLG-6', 'Client 6') + ['External_ID' => '888006']);

        $rows = (new ReportData)->fetch($connector, '2026-10-01', '2026-10-10');

        self::assertCount(7, $rows);
        self::assertSame(['888001', '888002', '', '', '', '', ''], array_column($rows, 'lending_tower_id'));
        self::assertStringNotContainsString('External_ID', $connector->queries[0]['sql']);
        self::assertSame(array_merge(array_column($rows, 'llg_id'), array_column($rows, 'llg_id')), $connector->queries[1]['params']);
    }

    public function test_source_lookup_is_bounded_below_sql_server_parameter_limit(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        for ($id = 1; $id <= 401; $id++) {
            $connector->insert('TblContacts', $this->contact('LLG-'.$id));
        }
        self::assertCount(401, (new ReportData)->fetch($connector, '2026-10-01', '2026-10-10'));
        self::assertCount(3, $connector->queries);
        self::assertCount(800, $connector->queries[1]['params']);
        self::assertCount(2, $connector->queries[2]['params']);
    }

    public function test_query_failure_cannot_turn_into_an_empty_report(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->failQuery = 1;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('synthetic query failure');
        (new ReportData)->fetch($connector, '2026-10-01', '2026-10-10');
    }

    public function test_failed_source_lookup_cannot_send_a_partially_enriched_report(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->insert('TblContacts', $this->contact('LLG-1'));
        $connector->failQuery = 2;
        $this->expectException(\RuntimeException::class);
        (new ReportData)->fetch($connector, '2026-10-01', '2026-10-10');
    }

    public function test_workbook_roundtrip_preserves_columns_ids_dates_and_literal_text(): void
    {
        $rows = [[
            'llg_id' => 'LLG-123', 'lending_tower_id' => '12345678901234567890',
            'client' => '=Example Client', 'status' => 'Active', 'created_date' => '2026-10-09',
        ], [
            'llg_id' => 'LLG-456', 'lending_tower_id' => '',
            'client' => 'Second Client', 'status' => 'Active', 'created_date' => '2026-10-09',
        ]];
        $file = (new Formatter)->buildWorkbook($rows, $this->window());
        $this->files[] = $file['path'];
        $workbook = IOFactory::load($file['path']);
        try {
            self::assertSame(1, $workbook->getSheetCount());
            $sheet = $workbook->getActiveSheet();
            self::assertSame(['Enrollment ID', 'Lending Tower ID', 'Client', 'Status', 'Created Date'], $sheet->rangeToArray('A5:E5')[0]);
            self::assertSame($rows[0]['lending_tower_id'], $sheet->getCell('B6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('C6')->getDataType());
            self::assertSame('=Example Client', $sheet->getCell('C6')->getValue());
            self::assertEquals(ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-10-09')), $sheet->getCell('E6')->getValue());
            self::assertSame('mm/dd/yyyy', $sheet->getStyle('E6')->getNumberFormat()->getFormatCode());
            self::assertSame('FFFFF2CC', $sheet->getStyle('B7')->getFill()->getStartColor()->getARGB());
            self::assertSame('A6', $sheet->getFreezePane());
            self::assertSame('A5:E7', $sheet->getAutoFilter()->getRange());
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function test_email_uses_tblreports_only_and_the_requested_message(): void
    {
        $email = new MissingAssignmentsFakeEmail;
        $formatter = new Formatter($email);
        $file = $formatter->buildWorkbook([], $this->window());
        $this->files[] = $file['path'];

        self::assertTrue($formatter->sendReport(new MissingAssignmentsFixtureConnector, $file, $this->window(), 2, 1));
        self::assertSame(['MissingAssignmentsReport', 'Missing Assignments Report'], $email->call['reportNames']);
        self::assertSame([], $email->call['companies']);
        self::assertFalse($email->call['includeEnvExtras']);
        self::assertStringContainsString('please review and assign the appropriate agent in the Lending Tower Forth CRM.', $email->call['body']);
        self::assertStringContainsString('Clients without a resolved Lending Tower ID: 1.', $email->call['body']);
        self::assertCount(1, $email->call['attachments']);
        self::assertSame(file_get_contents($file['path']), base64_decode($email->call['attachments'][0]['contentBytes'], true));
    }

    public function test_dry_run_does_not_build_send_or_write(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->insert('TblContacts', $this->contact('LLG-1'));
        $formatter = new MissingAssignmentsFakeFormatter($this->storageRoot.'/app/report.xlsx');
        [$status, $output] = $this->runCommand($connector, $formatter, ['--dry-run' => true]);
        self::assertSame(0, $status);
        self::assertStringContainsString('LLG-1', $output);
        self::assertSame(0, $formatter->builds);
        self::assertSame(0, $formatter->sends);
        self::assertSame([], glob($this->storageRoot.'/app/*'));
        self::assertCount(2, $connector->queries);
    }

    public function test_no_email_keeps_the_workbook(): void
    {
        $formatter = new MissingAssignmentsFakeFormatter($this->storageRoot.'/app/report.xlsx');
        $this->files[] = $formatter->path;
        [$status] = $this->runCommand(new MissingAssignmentsFixtureConnector, $formatter, ['--no-email' => true]);
        self::assertSame(0, $status);
        self::assertFileExists($formatter->path);
        self::assertSame(0, $formatter->sends);
    }

    public function test_failed_delivery_returns_failure_and_retains_the_workbook(): void
    {
        $formatter = new MissingAssignmentsFakeFormatter($this->storageRoot.'/app/report.xlsx');
        $formatter->sendResult = false;
        $this->files[] = $formatter->path;
        [$status] = $this->runCommand(new MissingAssignmentsFixtureConnector, $formatter);
        self::assertSame(1, $status);
        self::assertFileExists($formatter->path);
        self::assertSame(1, $formatter->sends);
    }

    public function test_successful_delivery_cleans_up_the_workbook_even_when_empty(): void
    {
        $formatter = new MissingAssignmentsFakeFormatter($this->storageRoot.'/app/report.xlsx');
        $this->files[] = $formatter->path;
        [$status] = $this->runCommand(new MissingAssignmentsFixtureConnector, $formatter);
        self::assertSame(0, $status);
        self::assertFileDoesNotExist($formatter->path);
        self::assertSame(1, $formatter->sends);
    }

    public function test_command_stops_on_failed_database_query(): void
    {
        $connector = new MissingAssignmentsFixtureConnector;
        $connector->failQuery = 1;
        $formatter = new MissingAssignmentsFakeFormatter($this->storageRoot.'/app/report.xlsx');
        [$status] = $this->runCommand($connector, $formatter);
        self::assertSame(1, $status);
        self::assertSame(0, $formatter->builds);
        self::assertSame(0, $formatter->sends);
    }

    public function test_empty_report_at_month_boundary_uses_the_friday_month_in_workbook_and_email(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 2, 16, 0, 0, 'America/Los_Angeles'));
        $email = new MissingAssignmentsFakeEmail;
        $formatter = new Formatter($email);
        $file = $formatter->buildWorkbook([], $this->window());
        $this->files[] = $file['path'];
        $workbook = IOFactory::load($file['path']);
        self::assertSame('Created dates: 2026-10-01 through 2026-10-30 (inclusive)', $workbook->getActiveSheet()->getCell('A2')->getValue());
        self::assertSame('No clients match the report criteria.', $workbook->getActiveSheet()->getCell('A6')->getValue());
        $workbook->disconnectWorksheets();
        self::assertTrue($formatter->sendReport(new MissingAssignmentsFixtureConnector, $file, $this->window(), 0, 0));
        self::assertStringContainsString('Created dates: 2026-10-01 through 2026-10-30 (inclusive)', $email->call['body']);
        self::assertStringContainsString('No clients match the report criteria.', $email->call['body']);
        self::assertStringNotContainsString('prior Friday precedes', $email->call['body']);
    }

    private function window(): array
    {
        return (new ReflectionMethod(GenerateMissingAssignmentsReport::class, 'resolveDateWindow'))->invoke(new GenerateMissingAssignmentsReport);
    }

    private function contact(string $id, string $client = 'Example Client', string $created = '2026-10-02 12:00:00'): array
    {
        return [
            'LLG_ID' => $id, 'Client' => $client, 'Created_Date' => $created,
            'Agent' => '', 'Stage' => 'Client', 'Status' => 'Active',
            'Email' => 'example@example.test', 'Phone' => '5551234567',
        ];
    }

    private function runCommand(DBConnector $connector, Formatter $formatter, array $options = []): array
    {
        $command = new class($connector, $formatter) extends GenerateMissingAssignmentsReport {
            public function __construct(private readonly DBConnector $connector, private readonly Formatter $formatter)
            {
                parent::__construct();
            }

            protected function initializeSqlServerConnector(): DBConnector { return $this->connector; }
            protected function createFormatter(): Formatter { return $this->formatter; }
        };
        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        return [$command->handle(), $output->fetch()];
    }
}

/** Executes the production query against isolated fixtures, adapting only SQL Server date syntax. */
final class MissingAssignmentsFixtureConnector extends DBConnector
{
    private \PDO $pdo;
    public array $queries = [];
    public int $failQuery = 0;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (['TblContacts', 'TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $this->pdo->exec("CREATE TABLE {$table} (LLG_ID TEXT, External_ID TEXT, Client TEXT, Created_Date TEXT, Agent TEXT, Stage TEXT, Status TEXT, Email TEXT, Phone TEXT)");
        }
    }

    public function insert(string $table, array $row): void
    {
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $statement = $this->pdo->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.$marks.')');
        $statement->execute(array_values($row));
    }

    public function querySqlServer(string $sql, array $params = []): array
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
            throw new \LogicException('Report attempted a database write.');
        }
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        if (count($this->queries) === $this->failQuery) {
            return ['success' => false, 'error' => 'synthetic query failure', 'data' => []];
        }
        $sql = str_replace('CONVERT(char(10), CAST(Created_Date AS date), 23)', 'date(Created_Date)', $sql);
        $sql = str_replace('CAST(Created_Date AS date)', 'date(Created_Date)', $sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return ['success' => true, 'data' => $statement->fetchAll(\PDO::FETCH_ASSOC)];
    }
}

final class MissingAssignmentsFakeEmail extends EmailSenderService
{
    public array $call = [];
    public function __construct() {}

    public function sendMailUsingTblReports(DBConnector $connector, array $reportNames, array $companies, string $subject, string $body, array $attachments = [], bool $includeEnvExtras = true, bool $strictCompany = false): bool
    {
        $this->call = compact('reportNames', 'companies', 'subject', 'body', 'attachments', 'includeEnvExtras');

        return true;
    }
}

final class MissingAssignmentsFakeFormatter extends Formatter
{
    public int $builds = 0;
    public int $sends = 0;
    public bool $sendResult = true;

    public function __construct(public readonly string $path) { parent::__construct(); }

    public function buildWorkbook(array $rows, array $window): array
    {
        $this->builds++;
        file_put_contents($this->path, 'synthetic workbook');

        return ['filename' => 'report.xlsx', 'path' => $this->path];
    }

    public function sendReport(DBConnector $connector, array $file, array $window, int $count, int $unresolved): bool
    {
        $this->sends++;

        return $this->sendResult;
    }
}
