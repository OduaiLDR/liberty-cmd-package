<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nov 5, 2026: a script lowered run 13's Selected but not its manifest, and that one row failed
 * GET /runs for everyone, which turned Generate off. In-memory SQLite only.
 */
final class SmsRunHistoryFileProblemTest extends TestCase
{
    private const BAD = '12345678-1234-4234-8234-123456789abc';
    private const GOOD = '22345678-1234-4234-8234-123456789abc';
    private Container $previous;
    private $db;
    private array $logged = [];
    private SmsRunService $service;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $container = new Container; Container::setInstance($container);
        $container->instance('config', new Repository(['sms-exports' => ['local_root' => sys_get_temp_dir(), 'record_workflow_enabled' => true, 'durable_availability_enabled' => true],
            'database' => ['default' => 'sqlsrv']]));
        $logged = &$this->logged;
        $container->instance('log', new class($logged) {
            public function __construct(private array &$logged) {}
            public function warning(string $message, array $context = []): void { $this->logged[] = [$message, $context]; }
        });
        $capsule = new Manager($container); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db = $capsule->getConnection('sqlsrv');
        $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
            '2026_10_22_000001_add_sms_run_file_options.php'] as $file) (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
        $this->service = new class(new SmsProspectExportRepository) extends SmsRunService {
            protected function dispatch(string $requestId, string $job): void { throw new \RuntimeException('No job may be dispatched.'); }
        };
        $this->addRun(self::GOOD, 3, null, null);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function addRun(string $id, int $selected, ?string $path, ?string $manifest, ?string $options = null): void
    {
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => $id, 'Target' => 3, 'Selected' => $selected, 'Status' => 'approved',
            'Selection_Policy' => SmsRecordPolicy::RECORDS, 'Created_By' => 'operator@example.invalid', 'Created_At' => '2026-11-04 18:00:00',
            'Updated_At' => '2026-11-04 18:00:00', 'Approved_At' => '2026-11-04 22:00:00', 'Approved_By' => 'operator@example.invalid',
            'File_Path' => $path, 'File_Bytes' => $path ? 10 : null, 'File_Sha256' => $path ? str_repeat('a', 64) : null, 'File_At' => $path ? '2026-11-04 19:00:00' : null,
            'Run_Options_JSON' => $options ?? json_encode(['target' => 3, 'rows_per_file' => 3, 'tiers' => ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'],
                'csv_format' => 'sms-four-column-v1']), 'Artifact_Manifest_JSON' => $manifest]);
    }

    public function test_a_manifest_that_disagrees_with_selected_does_not_fail_the_list(): void
    {
        // The real shape of the failure: the file record still counts the people who were released.
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', json_encode(['parts' => [], 'rows' => 3]));
        $items = $this->service->history()['items'];
        self::assertCount(2, $items);
        $bad = collect($items)->firstWhere('request_id', self::BAD);
        self::assertNull($bad->file);
        self::assertTrue($bad->file_problem);
        self::assertSame(2, $bad->selected);
        self::assertSame(['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'], $bad->options['tiers'], 'Good options still show.');
        self::assertObjectNotHasProperty('file_problem', collect($items)->firstWhere('request_id', self::GOOD));
    }

    public function test_the_overview_the_page_loads_still_works(): void
    {
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', 'not json');
        $overview = $this->service->overview();
        self::assertCount(2, $overview['recent']);
        self::assertNull($overview['open']);
    }

    public function test_stored_options_that_cannot_be_read_are_flagged_the_same_way(): void
    {
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', null, 'not json');
        $bad = collect($this->service->history()['items'])->firstWhere('request_id', self::BAD);
        self::assertTrue($bad->file_problem);
        self::assertNull($bad->options);
        self::assertNull($bad->file);
    }

    public function test_the_problem_is_logged_with_the_run_id_only(): void
    {
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', 'not json');
        $this->service->history();
        self::assertCount(1, $this->logged);
        self::assertSame(['request_id' => self::BAD], $this->logged[0][1]);
    }

    public function test_opening_that_run_directly_still_refuses(): void
    {
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', 'not json');
        try {
            $this->service->show(self::BAD);
            self::fail('A run with a broken receipt must not open as if it were fine.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
    }

    public function test_downloading_that_run_still_refuses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sms-file-problem-');
        $this->addRun(self::BAD, 2, $path, json_encode(['parts' => [], 'rows' => 3]));
        try {
            $this->service->filePath(self::BAD);
            self::fail('A run with a broken receipt must not download.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        } finally {
            unlink($path);
        }
    }

    public function test_a_logging_failure_does_not_fail_the_list(): void
    {
        Container::getInstance()->instance('log', new class {
            public function warning(): void { throw new RuntimeException('The log is down.'); }
        });
        Facade::clearResolvedInstances();
        $this->addRun(self::BAD, 2, '/private/run/SMS Export.zip', 'not json');
        self::assertCount(2, $this->service->history()['items']);
    }

    public function test_a_healthy_run_is_unchanged(): void
    {
        $item = $this->service->history()['items'][0];
        self::assertNull($item->file);
        self::assertObjectNotHasProperty('file_problem', $item);
    }
}
