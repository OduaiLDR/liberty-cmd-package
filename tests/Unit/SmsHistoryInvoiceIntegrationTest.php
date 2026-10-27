<?php

use Cmd\Reports\Http\Controllers\SmsWorkflowApiController;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/** Plain PHPUnit, using tests/bootstrap-workspace.php and only an in-memory SQLite DB. */
final class SmsHistoryInvoiceIntegrationTest extends TestCase
{
    private Container $previousContainer;
    private Connection $db;
    private MarketingReportRepository $marketing;
    private const WEEK = '2026-10-19';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository);
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->db = $capsule->getConnection('sqlsrv');
        $schema = $this->db->getSchemaBuilder();
        $schema->create('TblMarketing', function (Blueprint $t): void {
            $t->integer('PK')->primary(); $t->string('Drop_Name');
            $t->integer('SMS_Drops')->default(0); $t->dateTime('SMS_Last_Export_Date')->nullable();
            $t->integer('Amount_Dropped')->default(0);
            $t->string('Mail_Invoice_Number')->nullable(); $t->decimal('Mail_Drop_Cost', 12, 2)->default(0);
            $t->string('Data_Invoice_Number')->nullable(); $t->decimal('Data_Drop_Cost', 12, 2)->default(0);
        });
        $schema->create('TblSmsExports', function (Blueprint $t): void {
            $t->bigInteger('PK')->primary(); $t->string('SMS_Drop_Name'); $t->uuid('Request_ID')->collation('NOCASE');
            $t->string('Debt_Tier'); $t->date('Week_Start'); $t->dateTime('Exported_At');
            $t->bigInteger('SMS_Count'); $t->string('SMS_Invoice_Number')->nullable();
            $t->decimal('SMS_Cost', 12, 2)->default(0);
        });
        $schema->create('TblSmsExportSources', function (Blueprint $t): void {
            $t->bigInteger('SMS_Export_PK'); $t->integer('Marketing_PK'); $t->bigInteger('SMS_Count');
        });
        $schema->create('TblSMSDrops', function (Blueprint $t): void {
            $t->integer('PK')->primary(); $t->uuid('Request_ID')->nullable()->collation('NOCASE'); $t->string('Drop_Name');
            $t->string('Debt_Tier')->nullable(); $t->date('Drop_Date'); $t->integer('Amount_Dropped');
            $t->string('Invoice_Number')->nullable(); $t->decimal('Cost', 12, 2)->nullable();
            $t->dateTime('Created_At');
        });
        $schema->create('TblSMSRuns', function (Blueprint $t): void {
            $t->uuid('Request_ID')->primary()->collation('NOCASE'); $t->string('Status'); $t->dateTime('Sent_At')->nullable();
        });
        $schema->create('TblSMSHistory', function (Blueprint $t): void {
            $t->increments('PK'); $t->integer('SMS_Drop_PK')->nullable()->index();
            $t->string('Drop_Name')->nullable(); $t->uuid('Request_ID')->collation('NOCASE');
        });
        $this->marketing = new MarketingReportRepository;
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousContainer);
        parent::tearDown();
    }

    private function requestId(int $id): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $id);
    }

    private function legacy(int $pk, int $count = 1, string $week = self::WEEK): void
    {
        $this->db->table('TblSmsExports')->insert([
            'PK' => $pk, 'SMS_Drop_Name' => 'LEGACY'.$pk, 'Request_ID' => $this->requestId(9000 + $pk),
            'Debt_Tier' => 'T1', 'Week_Start' => $week, 'Exported_At' => $week.' 10:00:00', 'SMS_Count' => $count,
        ]);
    }

    private function seedRun(int $id, string $status = 'sent', ?string $sentAt = '2026-10-20 12:30:00'): void
    {
        $this->db->table('TblSMSRuns')->insert([
            'Request_ID' => $this->requestId($id), 'Status' => $status,
            'Sent_At' => $sentAt,
        ]);
    }

    private function drop(int $pk, ?int $request = 1, int $count = 1, string $date = self::WEEK): void
    {
        $this->db->table('TblSMSDrops')->insert([
            'PK' => $pk, 'Request_ID' => $request === null ? null : $this->requestId($request),
            'Drop_Name' => 'SMS'.$pk, 'Debt_Tier' => 'T2', 'Drop_Date' => $date,
            'Amount_Dropped' => $count, 'Created_At' => $date.' 09:00:00',
        ]);
    }

    private function history(int $drop, int $request = 1, ?string $name = null): void
    {
        $this->db->table('TblSMSHistory')->insert([
            'SMS_Drop_PK' => $drop, 'Drop_Name' => $name ?? 'SMS'.$drop, 'Request_ID' => $this->requestId($request),
        ]);
    }

    private function invoice(string $cost = '10.01', string $week = self::WEEK): void
    {
        $this->marketing->allocateInvoice(['kind' => 'sms', 'invoice_number' => 'INV-SMS', 'cost' => $cost, 'week' => $week]);
    }

    public function test_history_merges_legacy_and_sent_drops_with_collision_free_ui_shape(): void
    {
        $this->legacy(1, 3);
        $this->seedRun(1);
        $this->drop(1, 1, 5);
        $this->drop(2, null, 7);
        $this->history(2);
        $this->history(2); // Many recipients must not multiply a drop's count or cost.
        $rows = $this->marketing->smsExports('2026-10-25');
        self::assertSame(['TblSmsExports:1', 'TblSMSDrops:1', 'TblSMSDrops:2'], $rows->pluck('PK')->all());
        self::assertSame(15, $rows->sum('SMS_Count'));
        self::assertSame(12, $rows->sum('Confirmed_Count'));
        self::assertSame('exported_unconfirmed', $rows[0]->Activity_Status);
        self::assertFalse($rows[0]->Billable);
        foreach ($rows as $row) {
            foreach (['PK', 'SMS_Drop_Name', 'Debt_Tier', 'SMS_Count', 'Week_Start', 'Exported_At', 'SMS_Invoice_Number', 'SMS_Cost', 'sources'] as $field) {
                self::assertTrue(property_exists($row, $field), $field);
            }
            self::assertSame(self::WEEK, $row->Week_Start);
        }
        self::assertSame('2026-10-20 12:30:00', $rows[1]->Exported_At);
        self::assertSame($this->requestId(1), $rows[2]->Request_ID);
        self::assertSame([], $rows[2]->sources);
    }

    public function test_only_sent_runs_are_billable_even_when_unsent_runs_retain_history(): void
    {
        $this->legacy(1);
        foreach (['draft', 'queued', 'selecting', 'review', 'building_file', 'file_ready', 'sending', 'review_only', 'not_sent', 'reversing', 'discarded', 'failed', 'approving', 'external_registering', 'external_sent'] as $index => $status) {
            $id = $index + 1;
            $this->seedRun($id, $status);
            $this->drop($id, $id);
            $this->history($id, $id);
            $this->drop($id + 100, null);
            $this->history($id + 100, $id);
        }
        $this->drop(999, 999); // An orphan Request_ID does not establish a send either.
        self::assertSame(['TblSmsExports:1'], $this->marketing->smsExports(self::WEEK)->pluck('PK')->all());
        try { $this->invoice('1.00'); self::fail('Retained exports are not confirmed billing evidence.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('week', $error->errors()); }
        self::assertNull($this->db->table('TblSmsExports')->value('SMS_Invoice_Number'));
        self::assertSame(0, $this->db->table('TblSMSDrops')->whereNotNull('Invoice_Number')->count());
        self::assertSame(0, $this->db->table('TblSMSDrops')->whereNotNull('Cost')->count());
    }

    public function test_historical_linkage_is_exact_unambiguous_and_never_overrides_explicit_request_id(): void
    {
        $this->seedRun(1); $this->seedRun(2, 'not_sent');
        $this->seedRun(3, 'sent', '2026-10-12 12:00:00');
        $this->drop(1, null); $this->history(1); $this->history(1);
        $this->drop(2, null); $this->history(200, 1, 'SMS2'); // Same name, wrong PK.
        $this->drop(3, null); $this->history(3, 1, 'WRONG-NAME');
        $this->drop(4, null); $this->history(4, 1); $this->history(4, 2); // Ambiguous requests.
        $this->drop(5, 2); $this->history(5, 1); // Explicit not_sent must win over sent history.
        $this->drop(6, null); // No history, including no name-only inference.
        $this->drop(7, null); $this->history(7, 1); $this->history(7, 3); // Conflicting out-of-week send.
        self::assertSame(['TblSMSDrops:1'], $this->marketing->smsExports(self::WEEK)->pluck('PK')->all());
        $this->invoice('0.01');
        self::assertSame([1], $this->db->table('TblSMSDrops')->whereNotNull('Invoice_Number')->pluck('PK')->all());
    }

    public function test_prepared_week_one_drops_are_billed_only_in_the_actual_sent_week(): void
    {
        $this->seedRun(1);
        $this->seedRun(2, 'sent', '2026-10-26 12:00:00');
        $this->drop(1, 1, 1, '2026-10-12');
        $this->drop(2, null, 1, '2026-10-12'); $this->history(2);
        $this->drop(3, 2, 1, self::WEEK);
        self::assertSame([], $this->marketing->smsExports('2026-10-12')->pluck('PK')->all());
        self::assertSame(['TblSMSDrops:1', 'TblSMSDrops:2'], $this->marketing->smsExports(self::WEEK)->pluck('PK')->all());
        self::assertSame(['TblSMSDrops:3'], $this->marketing->smsExports('2026-10-26')->pluck('PK')->all());
        $this->invoice('0.01');
        self::assertSame([1, 2], $this->db->table('TblSMSDrops')->whereNotNull('Invoice_Number')->pluck('PK')->all());
    }

    public function test_california_week_boundaries_are_compared_against_utc_sent_at(): void
    {
        $this->seedRun(1, 'sent', '2026-10-19 06:59:59'); // Sunday 23:59:59 PDT.
        $this->seedRun(2, 'sent', '2026-10-19 07:00:00'); // Monday 00:00 PDT.
        $this->seedRun(3, 'sent', '2026-10-26 06:59:59');
        $this->seedRun(4, 'sent', '2026-10-26 07:00:00');
        $this->seedRun(5, 'sent', null); // Status alone cannot place a send in a week.
        $this->legacy(1, 1, '2026-10-12'); $this->legacy(2, 1, '2026-10-26');
        foreach (range(1, 5) as $id) $this->drop($id, $id, 1, '2026-10-12');
        self::assertSame(['TblSMSDrops:2', 'TblSMSDrops:3'], $this->marketing->smsExports('2026-10-25')->pluck('PK')->all());
        $this->invoice('0.01', '2026-10-25');
        self::assertSame([2, 3], $this->db->table('TblSMSDrops')->whereNotNull('Invoice_Number')->pluck('PK')->all());
        self::assertSame(0, $this->db->table('TblSmsExports')->whereNotNull('SMS_Invoice_Number')->count());
    }

    public function test_california_dst_week_converts_each_boundary_independently(): void
    {
        // Clocks move back during this week: its UTC interval is 169 hours, not 168.
        $this->seedRun(1, 'sent', '2026-10-26 06:59:59');
        $this->seedRun(2, 'sent', '2026-10-26 07:00:00');
        $this->seedRun(3, 'sent', '2026-11-02 07:59:59');
        $this->seedRun(4, 'sent', '2026-11-02 08:00:00');
        foreach (range(1, 4) as $id) $this->drop($id, $id, 1, '2026-10-12');
        self::assertSame(['TblSMSDrops:2', 'TblSMSDrops:3'], $this->marketing->smsExports('2026-11-01')->pluck('PK')->all());
        $this->invoice('0.01', '2026-11-01');
        self::assertSame([2, 3], $this->db->table('TblSMSDrops')->whereNotNull('Invoice_Number')->pluck('PK')->all());
    }

    public function test_api_history_reads_only_linkage_and_billing_columns_never_recipient_pii(): void
    {
        foreach (['TblSmsExports', 'TblSMSDrops', 'TblSMSHistory'] as $table) {
            $this->db->getSchemaBuilder()->table($table, function (Blueprint $t): void {
                $t->string('Phone')->nullable(); $t->string('Email')->nullable(); $t->string('Client')->nullable();
            });
        }
        $this->legacy(1); $this->seedRun(1); $this->drop(1, null); $this->history(1);
        foreach (['TblSmsExports', 'TblSMSDrops', 'TblSMSHistory'] as $table) {
            $this->db->table($table)->update(['Phone' => '2025550100', 'Email' => 'private@example.test', 'Client' => 'PRIVATE FIXTURE']);
        }
        $this->db->enableQueryLog();
        $response = (new SmsWorkflowApiController(new MailDropExportRepository, $this->marketing))
            ->history(Request::create('/', 'GET', ['week' => self::WEEK]));
        foreach (['2025550100', 'private@example.test', 'PRIVATE FIXTURE'] as $value) {
            self::assertStringNotContainsString($value, $response->getContent());
        }
        foreach ($this->db->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/select\s+(?:[\w".]+\.)?\*/i', $query['query']);
            self::assertDoesNotMatchRegularExpression('/\b(Phone|Email|Client|Address|Prospect_PK)\b/i', $query['query']);
        }
    }

    public function test_controller_attaches_legacy_sources_only_to_the_legacy_composite_id(): void
    {
        $this->legacy(9); $this->seedRun(1); $this->drop(9);
        $this->db->table('TblMarketing')->insert(['PK' => 7, 'Drop_Name' => 'MAIL7']);
        $this->db->table('TblSmsExportSources')->insert([
            ['SMS_Export_PK' => 9, 'Marketing_PK' => 7, 'SMS_Count' => 1],
            ['SMS_Export_PK' => 99, 'Marketing_PK' => 7, 'SMS_Count' => 999],
        ]);
        $controller = new SmsWorkflowApiController(new MailDropExportRepository, $this->marketing);
        $response = $controller->history(Request::create('/', 'GET', ['week' => '2026-10-25']));
        $data = $response->getData(true);
        self::assertSame(self::WEEK, $data['week']);
        self::assertSame('TblSmsExports:9', $data['exports'][0]['PK']);
        self::assertSame([['Marketing_PK' => 7, 'Drop_Name' => 'MAIL7', 'SMS_Count' => 1]], $data['exports'][0]['sources']);
        self::assertSame('TblSMSDrops:9', $data['exports'][1]['PK']);
        self::assertSame([], $data['exports'][1]['sources']);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_new_only_controller_history_does_not_query_or_borrow_legacy_sources(): void
    {
        $this->seedRun(1); $this->drop(1);
        $this->db->table('TblSmsExportSources')->insert(['SMS_Export_PK' => 1, 'Marketing_PK' => 5, 'SMS_Count' => 88]);
        $this->db->enableQueryLog();
        $data = (new SmsWorkflowApiController(new MailDropExportRepository, $this->marketing))
            ->history(Request::create('/', 'GET', ['week' => self::WEEK]))->getData(true);
        self::assertSame([], $data['exports'][0]['sources']);
        foreach ($this->db->getQueryLog() as $query) self::assertStringNotContainsString('from "TblSmsExportSources"', $query['query']);
    }

    public function test_one_invoice_total_is_allocated_only_across_confirmed_rows_without_pk_collision(): void
    {
        $this->legacy(1, 1); $this->seedRun(1); $this->drop(1, 1, 2); $this->drop(2, null, 3); $this->history(2);
        $this->invoice();
        self::assertSame(0.0, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
        self::assertNull($this->db->table('TblSmsExports')->value('SMS_Invoice_Number'));
        self::assertSame(4.00, (float) $this->db->table('TblSMSDrops')->where('PK', 1)->value('Cost'));
        self::assertSame(6.01, (float) $this->db->table('TblSMSDrops')->where('PK', 2)->value('Cost'));
        self::assertSame(['INV-SMS'], $this->marketing->smsExports(self::WEEK)->where('Billable', true)->pluck('SMS_Invoice_Number')->unique()->values()->all());
        self::assertSame(10.01, round((float) $this->marketing->smsExports(self::WEEK)->sum('SMS_Cost'), 2));
    }

    public function test_equal_count_remainders_use_stable_composite_order_for_a_single_cent(): void
    {
        $this->seedRun(1); $this->drop(2); $this->drop(1); $this->legacy(1);
        $this->invoice('0.01');
        self::assertSame(0.0, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
        self::assertSame([0.01, 0], $this->db->table('TblSMSDrops')->orderBy('PK')->pluck('Cost')->all());
    }

    public function test_any_existing_invoice_on_either_source_rejects_the_entire_week_without_changes(): void
    {
        $this->legacy(1); $this->seedRun(1); $this->drop(1); $this->drop(2, null); $this->history(2);
        foreach ([['TblSmsExports', 1, 'SMS_Invoice_Number'], ['TblSMSDrops', 1, 'Invoice_Number'], ['TblSMSDrops', 2, 'Invoice_Number']] as [$table, $pk, $column]) {
            $this->db->table($table)->where('PK', $pk)->update([$column => ' EXISTING ']);
            try {
                $this->invoice();
                self::fail('An existing invoice must reject the entire selection.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('week', $error->errors());
            }
            self::assertSame(' EXISTING ', $this->db->table($table)->where('PK', $pk)->value($column));
            self::assertSame(0.0, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
            self::assertSame(0, $this->db->table('TblSMSDrops')->whereNotNull('Cost')->count());
            $this->db->table($table)->where('PK', $pk)->update([$column => null]);
        }
    }

    public function test_no_confirmed_sends_or_zero_total_counts_cannot_be_invoiced(): void
    {
        $this->seedRun(1, 'review_only'); $this->drop(1);
        foreach ([false, true] as $zeroCount) {
            if ($zeroCount) { $this->legacy(1, 0); $this->seedRun(2); $this->drop(2, 2, 0); }
            try {
                $this->invoice(); self::fail('The empty or zero-count population must be rejected.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey($zeroCount ? 'cost' : 'week', $error->errors());
            }
        }
        self::assertNull($this->db->table('TblSMSDrops')->value('Invoice_Number'));
    }

    public function test_write_failure_rolls_back_both_legacy_and_new_invoice_updates(): void
    {
        $this->legacy(1); $this->seedRun(1); $this->drop(1);
        $this->db->unprepared("CREATE TRIGGER fail_sms_invoice BEFORE UPDATE ON TblSMSDrops BEGIN SELECT RAISE(ABORT, 'test invoice write failure'); END");
        try {
            $this->invoice(); self::fail('The test trigger must reject the second table update.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('test invoice write failure', $error->getMessage());
        }
        self::assertNull($this->db->table('TblSmsExports')->value('SMS_Invoice_Number'));
        self::assertSame(0.0, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
        self::assertNull($this->db->table('TblSMSDrops')->value('Invoice_Number'));
    }

    public function test_history_lookups_are_batched_and_grouped_without_joining_recipient_rows(): void
    {
        $runs = $drops = $history = [];
        for ($id = 1; $id <= 1001; $id++) {
            $runs[] = ['Request_ID' => $this->requestId($id), 'Status' => 'sent', 'Sent_At' => self::WEEK.' 12:00:00'];
            $drops[] = ['PK' => $id, 'Request_ID' => null, 'Drop_Name' => 'SMS'.$id, 'Debt_Tier' => 'T1',
                'Drop_Date' => self::WEEK, 'Amount_Dropped' => 1, 'Created_At' => self::WEEK.' 09:00:00'];
            $history[] = ['SMS_Drop_PK' => $id, 'Drop_Name' => 'SMS'.$id, 'Request_ID' => $this->requestId($id)];
        }
        foreach (['TblSMSRuns' => $runs, 'TblSMSDrops' => $drops, 'TblSMSHistory' => $history] as $table => $records) {
            foreach (array_chunk($records, 50) as $chunk) $this->db->table($table)->insert($chunk);
        }
        $this->db->enableQueryLog();
        self::assertCount(1001, $this->marketing->smsExports(self::WEEK));
        $queries = $this->db->getQueryLog();
        $runQueries = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'from "TblSMSRuns"'));
        self::assertCount(1, $runQueries);
        $runIndex = array_key_first($runQueries);
        self::assertStringContainsString('"Status" in (?, ?) and "Sent_At" >= ? and "Sent_At" < ?', $queries[$runIndex]['query']);
        self::assertSame(['sent', 'approved', '2026-10-19 07:00:00', '2026-10-26 07:00:00'], $queries[$runIndex]['bindings']);
        $candidateQueries = array_filter($queries, fn (array $q): bool => str_starts_with($q['query'], 'select distinct "h"."SMS_Drop_PK"'));
        self::assertCount(2, $candidateQueries);
        foreach ($candidateQueries as $index => $query) {
            self::assertLessThan($index, $runIndex, 'The sent-week run lookup must precede historical candidates.');
            self::assertStringContainsString('"h"."Request_ID" in (', $query['query']);
            self::assertStringContainsString('exists', $query['query']);
            self::assertLessThanOrEqual(1000, count($query['bindings']));
        }
        foreach ($queries as $index => $query) {
            if (! str_contains($query['query'], 'from "TblSMSDrops"')) continue;
            self::assertLessThan($index, $runIndex, 'Lock/read runs before reading their drops.');
            self::assertStringContainsString(' in (', $query['query']);
            self::assertStringNotContainsString('Drop_Date', $query['query']);
        }
        $lookups = array_filter($queries, fn (array $q): bool => str_starts_with($q['query'], 'select "SMS_Drop_PK"'));
        self::assertCount(2, $lookups);
        foreach ($lookups as $query) {
            self::assertLessThanOrEqual(1000, count($query['bindings']));
            self::assertStringContainsString('group by', $query['query']);
            self::assertStringNotContainsString('join', $query['query']);
        }
    }

    public function test_legacy_only_schema_retains_history_but_cannot_infer_billing_evidence(): void
    {
        foreach (['TblSMSHistory', 'TblSMSRuns', 'TblSMSDrops'] as $table) $this->db->getSchemaBuilder()->drop($table);
        $this->legacy(1);
        self::assertSame(['TblSmsExports:1'], $this->marketing->smsExports(self::WEEK)->pluck('PK')->all());
        self::assertFalse($this->marketing->smsExports(self::WEEK)[0]->Billable);
        try { $this->invoice('1.23'); self::fail('A legacy-only export cannot prove submission.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('week', $error->errors()); }
        self::assertSame(0.0, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
    }

    public function test_exact_run_states_classify_retained_exports_without_promoting_them_to_sends(): void
    {
        foreach (['queued', 'selecting', 'review', 'file_ready', 'approving', 'failed', 'discarded', 'not_sent', 'approved', 'sent', 'external_sent'] as $index => $status) {
            $pk = $index + 1;
            $this->legacy($pk);
            $this->seedRun(9000 + $pk, $status);
        }
        $rows = $this->marketing->smsExports(self::WEEK);
        self::assertSame(0, $rows->sum('Confirmed_Count'));
        foreach ($rows as $row) {
            self::assertFalse($row->Billable);
            self::assertSame(in_array($row->Run_Status, ['approved', 'sent', 'external_sent'], true) ? 'exported_unconfirmed' : $row->Run_Status, $row->Activity_Status);
        }
    }

    public function test_exact_request_duplicates_count_official_tiers_once_and_never_invoice_legacy_again(): void
    {
        $this->legacy(1, 2); $this->legacy(2, 3);
        $this->db->table('TblSmsExports')->update(['Request_ID' => $this->requestId(1), 'Debt_Tier' => 'T2']);
        $this->seedRun(1, 'approved'); $this->drop(1, 1, 2); $this->drop(2, 1, 3);
        $rows = $this->marketing->smsExports(self::WEEK);
        self::assertSame(5, $rows->sum('Confirmed_Count'));
        self::assertSame(['reconciled_export', 'reconciled_export', 'approved', 'approved'], $rows->pluck('Activity_Status')->all());
        self::assertSame([null], $rows->pluck('Invoice_Hold')->unique()->values()->all());
        $this->invoice();
        self::assertSame(0, $this->db->table('TblSmsExports')->whereNotNull('SMS_Invoice_Number')->count());
        self::assertSame(10.01, round((float) $this->db->table('TblSMSDrops')->sum('Cost'), 2));
        try { $this->invoice(); self::fail('Replay must not invoice again.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('week', $error->errors()); }
    }

    public function test_cross_week_exact_legacy_invoice_or_conflicting_counts_hold_official_allocation(): void
    {
        $this->legacy(1, 5, '2026-10-12');
        $this->db->table('TblSmsExports')->update(['Request_ID' => $this->requestId(1), 'Debt_Tier' => 'T2', 'SMS_Invoice_Number' => 'OLD-INV', 'SMS_Cost' => '1.23']);
        $this->seedRun(1); $this->drop(1, 1, 5);
        foreach (['invoice', 'count', 'tier'] as $cause) {
            if ($cause === 'count') $this->db->table('TblSmsExports')->update(['SMS_Invoice_Number' => null, 'SMS_Count' => 6]);
            if ($cause === 'tier') $this->db->table('TblSmsExports')->update(['SMS_Count' => 5, 'Debt_Tier' => 'T1']);
            $rows = $this->marketing->smsExports(self::WEEK);
            self::assertSame(5, $rows->sum('Confirmed_Count'));
            self::assertNotNull($rows[0]->Invoice_Hold);
            try { $this->invoice(); self::fail('Conflicting or previously billed exact evidence must hold.'); }
            catch (ValidationException $error) { self::assertArrayHasKey('week', $error->errors()); }
            self::assertNull($this->db->table('TblSMSDrops')->value('Invoice_Number'));
            self::assertSame(1.23, (float) $this->db->table('TblSmsExports')->value('SMS_Cost'));
        }
    }

    public function test_uuid_case_does_not_hide_an_exact_duplicate_invoice(): void
    {
        // SQLite collation models SQL Server's native uniqueidentifier equality.
        $id = 'abcdef01-1234-4123-8123-abcdef012345';
        $this->legacy(1); $this->seedRun(1); $this->drop(1);
        $this->db->table('TblSMSRuns')->update(['Request_ID' => $id]);
        $this->db->table('TblSMSDrops')->update(['Request_ID' => strtoupper($id)]);
        $this->db->table('TblSmsExports')->update(['Request_ID' => strtoupper($id), 'Debt_Tier' => 'T2', 'SMS_Invoice_Number' => 'OLD']);
        $rows = $this->marketing->smsExports(self::WEEK);
        self::assertSame(1, $rows->sum('Confirmed_Count'));
        self::assertSame('reconciled_export', $rows[0]->Activity_Status);
        self::assertNotNull($rows[1]->Invoice_Hold);
        try { $this->invoice(); self::fail('UUID letter case is not a new billing identity.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('week', $error->errors()); }
        self::assertNull($this->db->table('TblSMSDrops')->value('Invoice_Number'));
    }

    public function test_same_name_date_or_numeric_pk_never_establishes_a_duplicate_request(): void
    {
        $this->legacy(1, 99); $this->seedRun(1); $this->drop(1, 1, 5);
        $this->db->table('TblSmsExports')->update(['SMS_Drop_Name' => 'SMS1']);
        $rows = $this->marketing->smsExports(self::WEEK);
        self::assertSame('exported_unconfirmed', $rows[0]->Activity_Status);
        self::assertNull($rows[1]->Invoice_Hold);
        $this->invoice();
        self::assertNull($this->db->table('TblSmsExports')->value('SMS_Invoice_Number'));
    }

    public function test_mail_and_data_allocation_remain_scoped_to_the_named_marketing_drop(): void
    {
        $this->db->table('TblMarketing')->insert([
            ['PK' => 1, 'Drop_Name' => 'MAIL', 'Amount_Dropped' => 1],
            ['PK' => 2, 'Drop_Name' => 'MAIL', 'Amount_Dropped' => 2],
            ['PK' => 3, 'Drop_Name' => 'OTHER', 'Amount_Dropped' => 1],
        ]);
        foreach (['mail' => '10.00', 'data' => '0.01'] as $kind => $cost) {
            $this->marketing->allocateInvoice(['kind' => $kind, 'drop_name' => 'MAIL', 'cost' => $cost, 'invoice_number' => 'INV-'.$kind]);
        }
        self::assertSame([3.33, 6.67, 0.0], $this->db->table('TblMarketing')->orderBy('PK')->pluck('Mail_Drop_Cost')->map(fn ($v) => (float) $v)->all());
        self::assertSame([0.0, 0.01, 0.0], $this->db->table('TblMarketing')->orderBy('PK')->pluck('Data_Drop_Cost')->map(fn ($v) => (float) $v)->all());
        self::assertNull($this->db->table('TblMarketing')->where('PK', 3)->value('Mail_Invoice_Number'));
    }
}
