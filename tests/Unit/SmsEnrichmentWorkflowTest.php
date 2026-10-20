<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\CarbonImmutable;
use Cmd\Reports\Repositories\SmsEnrichmentRepository;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * No application bootstrap, .env, queue worker, SQL Server, provider or network is used.
 * From the package directory, using the stable isolated workspace harness:
 * php ../local-dev/sms-test-harness/vendor/bin/pest \
 *   --bootstrap ../local-dev/sms-test-harness/bootstrap.php --no-configuration \
 *   --do-not-cache-result tests/Unit/SmsEnrichmentWorkflowTest.php
 */
final class SmsEnrichmentWorkflowTest extends TestCase
{
    private Container $previousContainer;
    private Connection $db;
    private SmsEnrichmentRepository $repository;
    private object $migration;
    private int $refreshes;
    private array $refreshTransactionLevels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->refreshes = 0;
        $this->refreshTransactionLevels = [];
        $summary = Mockery::mock(SmsAvailabilityService::class);
        $summary->shouldReceive('invalidate')->andReturnUsing(function (): array {
            $this->refreshTransactionLevels[] = $this->db->transactionLevel();
            $this->refreshes++;
            return ['state' => 'pending'];
        });
        $container->instance(SmsAvailabilityService::class, $summary);
        $container->instance('db.schema', $this->db->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->db->statement('PRAGMA foreign_keys = ON');
        // Match case through the database's collation, with no functions on indexed columns.
        $this->db->getPdo()->sqliteCreateCollation('NOCASE', static fn (string $a, string $b): int => strcmp(mb_strtolower($a, 'UTF-8'), mb_strtolower($b, 'UTF-8')));
        CarbonImmutable::setTestNow('2026-10-19 18:00:00 UTC');
        $prospects = require __DIR__.'/../../database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php';
        $prospects->up();
        $this->migration = require __DIR__.'/../../database/sms-migrations/2026_10_19_000002_create_sms_enrichment_tables.php';
        $this->migration->up();
        $this->repository = new SmsEnrichmentRepository;
    }

    protected function tearDown(): void
    {
        foreach ($this->refreshTransactionLevels as $level) self::assertSame(0, $level);
        Mockery::close();
        CarbonImmutable::setTestNow();
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousContainer);
        parent::tearDown();
    }

    public function test_stage_deduplicates_people_across_chunks_active_batches_and_existing_prospects(): void
    {
        $this->repository->stage('other', [$this->record('Waiting')], 'operator');
        $this->db->table('TblProspects')->insert(['Client' => 'EXISTING PERSON', 'Address' => '10 TEST WAY', 'Zip' => '00123']);
        $summary = $this->repository->stage('main', [
            $this->record('New'), $this->record(' new ', ['Address' => ' 10 TEST WAY ', 'Zip' => '00123-9999']),
            $this->record('Existing Person'), $this->record('Waiting'), $this->record('Bad', ['Zip' => '0012x']),
        ], 'operator');
        self::assertSame([5, 1, 1, 2, 1], $this->counts($summary, ['records', 'submitted', 'existing', 'duplicate', 'rejected']));
        $summary = $this->repository->stage('main', [$this->record('New'), $this->record('Another')], 'operator', 1);
        self::assertSame([7, 2, 1, 3, 1], $this->counts($summary, ['records', 'submitted', 'existing', 'duplicate', 'rejected']));
        self::assertSame(2, count($this->rows('main')));
        self::assertSame(1, $this->db->table('TblProspects')->count());
    }

    public function test_chunk_replay_is_immutable_but_associative_field_order_is_irrelevant(): void
    {
        $record = $this->record('New');
        $before = $this->repository->stage('main', [$record], 'operator', 7);
        self::assertSame($before, $this->repository->stage('main', [array_reverse($record, true)], 'operator', 7));
        $this->http(409, fn () => $this->repository->stage('main', [$this->record('Changed')], 'operator', 7));
        self::assertSame($before, $this->repository->summary('main'));
        self::assertSame(1, $this->db->table('TblSMSEnrichmentChunks')->count());
    }

    public function test_fast_staging_returns_immutable_chunk_counts_without_cumulative_aggregation(): void
    {
        $this->db->enableQueryLog();
        $first = $this->repository->stage('main', [$this->record('One'), $this->record('One'), $this->record('Bad', ['Zip' => 'bad'])], 'operator', 0, false);
        self::assertSame(['batch_id' => 'main', 'chunk' => 0, 'records' => 3, 'rejected' => 1,
            'existing' => 0, 'duplicate' => 1, 'submitted' => 1], $first);
        $second = $this->repository->stage('main', [$this->record('Two')], 'operator', 1, false);
        self::assertSame(1, $second['submitted']);
        self::assertSame($first, $this->repository->stage('main', [$this->record('One'), $this->record('One'), $this->record('Bad', ['Zip' => 'bad'])], 'operator', 0, false));
        $this->assertNoSummaryQueries();
        $this->db->disableQueryLog();
        self::assertSame([4, 2], $this->counts($this->repository->summary('main'), ['records', 'submitted']));
    }

    public function test_fast_returns_count_only_new_work_and_skip_summary_queries_including_replays_and_completion(): void
    {
        $this->repository->stage('main', [$this->record('One'), $this->record('Two'), $this->record('Three')], 'operator');
        $rows = $this->rows('main');
        $result = ['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '2025550101'];
        $this->db->enableQueryLog();
        $first = $this->repository->returned('main', [$result], false, false);
        self::assertSame([1, 0, 1, 1], $this->counts($first, ['returned', 'replayed', 'phone', 'added']));
        $replay = $this->repository->returned('main', [$result], false, false);
        self::assertSame([0, 1, 0, 0], $this->counts($replay, ['returned', 'replayed', 'phone', 'added']));
        $closed = $this->repository->returned('main', [['Record_ID' => $rows[1]['Record_ID']]], true, false);
        self::assertSame([1, 1, 1], $this->counts($closed, ['returned', 'neither', 'closed_unreturned']));
        $this->assertNoSummaryQueries();
        $this->db->disableQueryLog();
        $summary = $this->repository->summary('main');
        self::assertSame([2, 1, 1, 1], $this->counts($summary, ['returned', 'phone', 'neither', 'closed_unreturned']));
        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $this->refreshes, 'Only a newly admitted contact requests a refresh, not replays or empty returns.');
    }

    public function test_return_refresh_waits_for_the_outer_write_and_is_discarded_on_rollback(): void
    {
        $this->repository->stage('main', [$this->record('One')], 'operator');
        $id = $this->rows('main')[0]['Record_ID'];
        $result = ['Record_ID' => $id, 'Phone' => '2025550101'];
        try {
            $this->db->transaction(function () use ($result): void {
                $this->repository->returned('main', [$result], false, false);
                self::assertSame(0, $this->refreshes);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic rollback', $error->getMessage());
        }
        self::assertSame(0, $this->refreshes);
        self::assertSame(0, $this->db->table('TblProspects')->count());
        $this->repository->returned('main', [$result]);
        self::assertSame(1, $this->refreshes, 'The summary-returning API also notifies once after a successful commit.');
    }

    public function test_export_freezes_membership_and_keeps_the_same_vendor_contract_after_returns(): void
    {
        $source = $this->record('Ada Marie Sample', ['Phone' => '2025550199', 'Email' => 'ignored@example.test']);
        $this->repository->stage('main', [$source], 'operator');
        $rows = $this->rows('main');
        self::assertSame(['Record_ID', 'First_Name', 'Middle_Name', 'Last_Name', 'Address', 'City', 'State', 'Zip_Code', 'Phone_1', 'Email_1'], array_keys($rows[0]));
        self::assertMatchesRegularExpression('/^SMSP-[1-9][0-9]*$/', $rows[0]['Record_ID']);
        self::assertSame(['Ada', 'Marie', 'Sample', '00123', '', ''], array_values(array_intersect_key($rows[0], array_flip(['First_Name', 'Middle_Name', 'Last_Name', 'Zip_Code', 'Phone_1', 'Email_1']))));
        $this->http(409, fn () => $this->repository->stage('main', [$this->record('Late')], 'operator', 1));
        self::assertSame('awaiting_return', $this->repository->stage('main', [$source], 'operator')['status']);
        $this->repository->returned('main', [['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '2025550101']], true);
        self::assertSame($rows, $this->rows('main'));
    }

    public function test_overlength_and_malformed_identities_are_rejected_without_truncation_collisions(): void
    {
        $summary = $this->repository->stage('main', [
            $this->record(str_repeat('A', 150).'X'), $this->record(str_repeat('A', 150).'Y'),
            $this->record('Long Address', ['Address' => str_repeat('B', 201)]),
            $this->record(''), $this->record('Missing Address', ['Address' => ' ']),
            $this->record('Bad Zip', ['Zip' => '00123oops']), $this->record('Bad Date', ['Creation_Date' => '2026-02-30']),
            $this->record('Control' . "\0" . 'Name'), $this->record(str_repeat('A', 150)),
        ], 'operator');
        self::assertSame([9, 8, 1], $this->counts($summary, ['records', 'rejected', 'submitted']));
        self::assertSame(str_repeat('A', 150), $this->db->table('TblSMSEnrichmentPeople')->value('Client'));
    }

    public function test_identity_casefold_preserves_display_case_and_does_not_collapse_internal_spaces_or_delimiters(): void
    {
        $this->db->table('TblProspects')->insert(['Client' => 'ÉLODIE SAMPLE', 'Address' => '10 TEST WAY', 'Zip' => '00123']);
        $summary = $this->repository->stage('main', [
            $this->record('élodie sample'), $this->record('One Two'), $this->record('One  Two'),
            $this->record('A|B', ['Address' => 'C']), $this->record('A', ['Address' => 'B|C']),
        ], 'operator');
        self::assertSame([1, 4, 0], $this->counts($summary, ['existing', 'submitted', 'duplicate']));
        self::assertSame('One Two', $this->db->table('TblSMSEnrichmentPeople')->where('Status', 'pending')->orderBy('PK')->value('Client'));
    }

    public function test_filtered_unique_index_protects_active_people_but_allows_multiple_terminal_nulls(): void
    {
        $this->repository->stage('first', [$this->record('One'), $this->record('Two')], 'operator');
        $index = $this->db->selectOne("SELECT sql FROM sqlite_master WHERE name = 'UQ_SMSEnrichmentPeople_Active'");
        self::assertStringContainsString('WHERE Active_Key IS NOT NULL', $index->sql);
        $rows = $this->rows('first');
        $this->repository->returned('first', array_map(static fn (array $r): array => ['Record_ID' => $r['Record_ID']], $rows), true);
        self::assertSame(2, $this->db->table('TblSMSEnrichmentPeople')->whereNull('Active_Key')->count());
        $this->repository->stage('second', [$this->record('One', ['Mailer_PK' => 900001])], 'operator');
        $active = (array) $this->db->table('TblSMSEnrichmentPeople')->whereNotNull('Active_Key')->first();
        unset($active['PK']);
        $active['Person_Key'] = str_repeat('f', 64);
        $this->expectException(QueryException::class);
        $this->db->table('TblSMSEnrichmentPeople')->insert($active);
    }

    public function test_return_uses_only_pending_identity_and_validated_contact_fields(): void
    {
        $this->repository->stage('main', [$this->record('Original Person', ['Tier' => 'Tier 4'])], 'operator');
        $id = $this->rows('main')[0]['Record_ID'];
        $summary = $this->repository->returned('main', [[
            'Record_ID' => $id, 'Phone Append 1' => '+1 (202) 555-0101', 'Email Append 1' => '  source@example.test  ',
            'Client' => 'Provider Renamed', 'Address' => 'Wrong address', 'Zip' => '99999', 'Tier' => 'T9',
            'Creation_Date' => '2000-01-01', 'Contacted' => 1, 'Opt_Out' => 1, 'SMS_Attempts' => 999,
        ]]);
        self::assertSame([1, 1, 1, 1, 0, 1], $this->counts($summary, ['returned', 'phone', 'email', 'both', 'neither', 'added']));
        $prospect = $this->db->table('TblProspects')->first();
        self::assertSame(['Original Person', '10 Test Way', '00123', 'T4', '2026-10-12', '2025550101', 'source@example.test'],
            [$prospect->Client, $prospect->Address, $prospect->Zip, $prospect->Tier, $prospect->Creation_Date, $prospect->Phone, $prospect->Email]);
        self::assertSame([0, 0, 0, 0, null], [(int) $prospect->Contacted, (int) $prospect->Invalid_Phone, (int) $prospect->Opt_Out, (int) $prospect->SMS_Attempts, $prospect->Latest_Export_Date]);
    }

    public function test_neither_and_invalid_contacts_do_not_create_prospects_and_release_identity_for_later_purchase(): void
    {
        $this->repository->stage('first', [$this->record('One'), $this->record('Two')], 'operator');
        $rows = $this->rows('first');
        $summary = $this->repository->returned('first', [
            ['Record_ID' => $rows[0]['Record_ID']],
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '123', 'Email' => 'invalid@'],
        ]);
        self::assertSame([2, 2, 0, 1, 1], $this->counts($summary, ['returned', 'neither', 'added', 'invalid_phone', 'invalid_email']));
        self::assertSame(0, $this->db->table('TblProspects')->count());
        $summary = $this->repository->stage('repurchase', [$this->record('One', ['Mailer_PK' => 900001]), $this->record('Two', ['Mailer_PK' => 900002])], 'operator');
        self::assertSame(2, $summary['submitted']);
    }

    public function test_one_usable_contact_is_enough_and_discarded_returns_remain_auditable(): void
    {
        $this->repository->stage('contact-rule', array_map(fn (string $name): array => $this->record($name),
            ['Phone survives', 'Email survives', 'Neither survives']), 'operator');
        $rows = $this->rows('contact-rule');
        $results = [
            ['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '+1 (202) 555-0101', 'Email' => 'not-an-email'],
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '123', 'Email' => '  usable@example.test  '],
            ['Record_ID' => $rows[2]['Record_ID'], 'Phone' => '123', 'Email' => 'not-an-email'],
        ];

        $summary = $this->repository->returned('contact-rule', $results, true);
        self::assertSame([3, 1, 1, 0, 1, 2], $this->counts($summary, ['returned', 'phone', 'email', 'both', 'neither', 'added']));
        self::assertSame(['Email survives', 'Phone survives'],
            $this->db->table('TblProspects')->orderBy('Client')->pluck('Client')->all());
        $emailOnly = $this->db->table('TblProspects')->where('Client', 'Email survives')->first();
        self::assertNull($emailOnly->Phone);
        self::assertSame('usable@example.test', $emailOnly->Email);
        self::assertSame(3, $this->db->table('TblSMSEnrichmentPeople')->whereNotNull('Returned_At')->count(),
            'Discarding a return from the prospect pool must not erase its return ledger.');
        $discarded = $this->db->table('TblSMSEnrichmentPeople')->where('Client', 'Neither survives')->first();
        self::assertNull($discarded->Prospect_PK);
        self::assertNull($discarded->Active_Key);
        self::assertSame('returned', $discarded->Status);
        self::assertSame(1, $this->refreshes);

        self::assertSame($summary, $this->repository->returned('contact-rule', $results, true));
        self::assertSame(2, $this->db->table('TblProspects')->count());
        self::assertSame(1, $this->refreshes, 'Replaying the same return must not admit it again or request another refresh.');
    }

    public function test_a_no_result_never_resubmits_the_same_purchase_but_a_new_purchase_can_retry(): void
    {
        $this->repository->stage('first', [$this->record('One', ['Mailer_PK' => 10001])], 'operator');
        $id = $this->rows('first')[0]['Record_ID'];
        $this->repository->returned('first', [['Record_ID' => $id]], true);
        $summary = $this->repository->stage('daily-repeat', [$this->record('One', ['Mailer_PK' => 10001])], 'operator');
        self::assertSame([1, 0, 1], $this->counts($summary, ['records', 'submitted', 'duplicate']));
        self::assertSame([], $this->rows('daily-repeat'));
        // The old source comes first in an overlapping date range. It must not hide
        // the later purchase of the same person during person-key deduplication.
        $summary = $this->repository->stage('new-purchase', [
            $this->record('One', ['Mailer_PK' => 10001]), $this->record('One', ['Mailer_PK' => 10002]),
        ], 'operator');
        self::assertSame([2, 1, 1], $this->counts($summary, ['records', 'submitted', 'duplicate']));
        self::assertSame(10002, (int) $this->db->table('TblSMSEnrichmentPeople')->where('Batch_ID', 'new-purchase')->value('Mailer_PK'));
        self::assertSame(1, $this->repository->stage('changed-identity', [$this->record('Different Name', ['Mailer_PK' => 10001])], 'operator')['duplicate']);
    }

    public function test_purchase_dedupe_applies_across_chunks_and_is_backed_by_a_filtered_unique_index(): void
    {
        $this->repository->stage('main', [$this->record('One', ['Mailer_PK' => 10001])], 'operator');
        $summary = $this->repository->stage('main', [$this->record('Different', ['Mailer_PK' => 10001])], 'operator', 1);
        self::assertSame([1, 1], $this->counts($summary, ['submitted', 'duplicate']));
        $row = (array) $this->db->table('TblSMSEnrichmentPeople')->first();
        unset($row['PK']);
        $row['Client'] = 'Another Identity';
        $row['Person_Key'] = $row['Active_Key'] = str_repeat('f', 64);
        try {
            $this->db->table('TblSMSEnrichmentPeople')->insert($row);
            self::fail('A submitted purchase must stay unique independently of its person hash.');
        } catch (QueryException $error) {
            self::assertStringContainsString('TblSMSEnrichmentPeople.Mailer_PK', $error->getMessage());
        }
    }

    public function test_database_equivalent_identities_with_different_hashes_dedupe_within_and_across_batches(): void
    {
        $this->useEquivalentIdentityCollation();
        $summary = $this->repository->stage('main', [
            $this->record('Groß Person', ['Mailer_PK' => 10001]), $this->record('Gross Person', ['Mailer_PK' => 10002]),
        ], 'operator');
        self::assertSame([1, 1], $this->counts($summary, ['submitted', 'duplicate']));
        $summary = $this->repository->stage('main', [$this->record('Gross Person', ['Mailer_PK' => 10003])], 'operator', 1);
        self::assertSame([1, 2], $this->counts($summary, ['submitted', 'duplicate']));
        self::assertSame(1, $this->repository->stage('other', [$this->record('Gross Person', ['Mailer_PK' => 10004])], 'operator')['duplicate']);
        self::assertSame(1, $this->db->table('TblSMSEnrichmentPeople')->where('Batch_ID', 'main')->count());
    }

    public function test_database_equivalence_is_respected_across_internal_parts_and_against_normalized_prospects(): void
    {
        $this->useEquivalentIdentityCollation();
        $records = [$this->record('Groß Person', ['Mailer_PK' => 10001])];
        for ($i = 0; $i < 199; $i++) $records[] = $this->record('Filler '.$i, ['Mailer_PK' => 20000 + $i]);
        $records[] = $this->record('Gross Person', ['Mailer_PK' => 10002]);
        $summary = $this->repository->stage('main', $records, 'operator');
        self::assertSame([200, 1], $this->counts($summary, ['submitted', 'duplicate']));
        $this->db->table('TblProspects')->insert(['Client' => 'Groß Existing', 'Address' => '10 Test Way', 'Zip' => '00123']);
        self::assertSame(1, $this->repository->stage('existing', [$this->record('Gross Existing')], 'operator')['existing']);
    }

    public function test_active_identity_index_protects_database_equivalence_independently_of_hashes(): void
    {
        $this->useEquivalentIdentityCollation();
        $this->repository->stage('main', [$this->record('Groß Person')], 'operator');
        $row = (array) $this->db->table('TblSMSEnrichmentPeople')->first();
        unset($row['PK']);
        $row['Client'] = 'Gross Person';
        $row['Mailer_PK'] = 900001;
        $row['Person_Key'] = $row['Active_Key'] = str_repeat('f', 64);
        try {
            $this->db->table('TblSMSEnrichmentPeople')->insert($row);
            self::fail('Database-equivalent active identities must stay unique independently of hashes.');
        } catch (QueryException $error) {
            self::assertStringContainsString('TblSMSEnrichmentPeople.Client', $error->getMessage());
        }
    }

    public function test_sql_server_match_predicates_leave_indexed_prospect_columns_bare(): void
    {
        $queries = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('sqlsrv');
        $connection->method('select')->willReturnCallback(static function (string $sql, array $bindings) use (&$queries): array {
            $queries[] = $sql;
            return [];
        });
        $manager = $this->createMock(DatabaseManager::class);
        $manager->method('connection')->with('sqlsrv')->willReturn($connection);
        \Illuminate\Support\Facades\DB::swap($manager);
        $person = $this->record('One') + ['Person_Key' => str_repeat('a', 64)];
        (new ReflectionMethod(SmsEnrichmentRepository::class, 'existingPeople'))->invoke($this->repository, [$person['Person_Key'] => $person]);
        self::assertStringContainsString('p.Client = CAST(v.Client AS varchar(150))', $queries[0]);
        self::assertStringContainsString('p.Address = CAST(v.Address AS varchar(200))', $queries[0]);
        self::assertStringContainsString('p.Zip = CAST(v.Zip AS varchar(10))', $queries[0]);
        self::assertDoesNotMatchRegularExpression('/(?:LOWER|LTRIM|RTRIM|CONVERT|LEFT|SUBSTR)\s*\([^)]*p\./i', $queries[0]);
        self::assertStringNotContainsString('BIN2', $queries[0]);
    }

    public function test_partial_results_reserve_nonreturned_people_until_explicit_complete(): void
    {
        $this->repository->stage('main', [$this->record('One'), $this->record('Two'), $this->record('Three')], 'operator');
        $rows = $this->rows('main');
        $summary = $this->repository->returned('main', [['Record_ID' => $rows[0]['Record_ID'], 'Email' => 'one@example.test']]);
        self::assertSame([3, 1, 2, 0], $this->counts($summary, ['submitted', 'returned', 'pending', 'closed_unreturned']));
        self::assertSame(1, $this->repository->stage('blocked', [$this->record('Two')], 'operator')['duplicate']);
        $summary = $this->repository->returned('main', [], true);
        self::assertSame([1, 0, 2], $this->counts($summary, ['returned', 'pending', 'closed_unreturned']));
        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $this->repository->stage('repurchase', [$this->record('Two', ['Mailer_PK' => 900001])], 'operator')['submitted']);
        $this->http(409, fn () => $this->repository->returned('main', [['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '2025550102']]));
        self::assertSame($summary, $this->repository->returned('main', [['Record_ID' => $rows[0]['Record_ID'], 'Email' => 'one@example.test']], true));
    }

    public function test_summary_counts_each_contact_combination_without_counting_missing_returns_as_neither(): void
    {
        $this->repository->stage('main', array_map(fn (string $name): array => $this->record($name), ['Both', 'Phone', 'Email', 'Neither', 'Waiting']), 'operator');
        $rows = $this->rows('main');
        $summary = $this->repository->returned('main', [
            ['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '2025550101', 'Email' => 'both@example.test'],
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '2025550102'],
            ['Record_ID' => $rows[2]['Record_ID'], 'Email' => 'email@example.test'],
            ['Record_ID' => $rows[3]['Record_ID']],
        ]);
        self::assertSame([5, 4, 1, 2, 2, 1, 1, 3], $this->counts($summary, ['submitted', 'returned', 'pending', 'phone', 'email', 'both', 'neither', 'added']));
        self::assertSame(3, $this->db->table('TblProspects')->count());
    }

    public function test_return_rejects_known_but_nonexportable_ledger_ids(): void
    {
        $this->db->table('TblProspects')->insert(['Client' => 'Existing', 'Address' => '10 Test Way', 'Zip' => '00123']);
        $this->repository->stage('main', [$this->record('Existing'), $this->record('New')], 'operator');
        $pk = $this->db->table('TblSMSEnrichmentPeople')->where('Status', 'existing')->value('PK');
        $this->http(422, fn () => $this->repository->returned('main', [['Record_ID' => 'SMSP-'.$pk, 'Phone' => '2025550101']], true));
        self::assertSame([0, 1], $this->counts($this->repository->summary('main'), ['returned', 'pending']));
    }

    public function test_unknown_or_foreign_id_rejects_the_entire_return_and_does_not_complete_batch(): void
    {
        $this->repository->stage('main', [$this->record('One')], 'operator');
        $this->repository->stage('other', [$this->record('Other')], 'operator');
        $id = $this->rows('main')[0]['Record_ID'];
        $foreign = $this->rows('other')[0]['Record_ID'];
        $before = $this->repository->summary('main');
        foreach ([$foreign, 'SMSP-999999', 'wrong-id'] as $bad) {
            $this->http(422, fn () => $this->repository->returned('main', [
                ['Record_ID' => $id, 'Phone' => '2025550101'], ['Record_ID' => $bad],
            ], true));
            self::assertSame($before, $this->repository->summary('main'));
            self::assertSame(0, $this->db->table('TblProspects')->count());
        }
    }

    public function test_replays_and_repeated_ids_never_increment_and_conflicts_roll_back_other_results(): void
    {
        $this->repository->stage('main', [$this->record('One'), $this->record('Two')], 'operator');
        $rows = $this->rows('main');
        $first = ['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '2025550101'];
        $before = $this->repository->returned('main', [$first, $first]);
        self::assertSame($before, $this->repository->returned('main', [$first]));
        $this->http(409, fn () => $this->repository->returned('main', [
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '2025550102'],
            ['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '2025550103'],
        ], true));
        self::assertSame($before, $this->repository->summary('main'));
        self::assertSame(1, $this->db->table('TblProspects')->count());
        $this->http(409, fn () => $this->repository->returned('main', [
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '2025550102'],
            ['Record_ID' => $rows[1]['Record_ID'], 'Phone' => '2025550103'],
        ]));
    }

    public function test_a_prospect_added_during_enrichment_only_gets_missing_contacts_and_keeps_all_flags_and_history(): void
    {
        $this->repository->stage('main', [$this->record('One')], 'operator');
        $id = $this->rows('main')[0]['Record_ID'];
        $pk = $this->db->table('TblProspects')->insertGetId([
            'Client' => 'ONE', 'Address' => '10 TEST WAY', 'Zip' => '00123', 'Tier' => 'T9',
            'Phone' => '2025550199', 'Email' => null, 'Contacted' => 1, 'Invalid_Phone' => 1, 'Opt_Out' => 1,
            'Response_Date' => '2026-09-20', 'Latest_Export_Date' => '2026-10-01', 'SMS_Attempts' => 8,
        ]);
        $before = (array) $this->db->table('TblProspects')->where('PK', $pk)->first();
        $summary = $this->repository->returned('main', [['Record_ID' => $id, 'Phone' => '2025550101', 'Email' => 'new@example.test']]);
        $after = (array) $this->db->table('TblProspects')->where('PK', $pk)->first();
        self::assertSame(array_replace($before, ['Email' => 'new@example.test']), $after);
        self::assertSame([1, 0, 1], $this->counts($summary, ['returned', 'added', 'filled']));
        self::assertSame($pk, (int) $this->db->table('TblSMSEnrichmentPeople')->value('Prospect_PK'));
    }

    public function test_storage_failure_rolls_back_all_admissions_results_and_completion(): void
    {
        $this->repository->stage('main', [$this->record('One'), $this->record('Failure')], 'operator');
        $rows = $this->rows('main');
        $before = $this->repository->summary('main');
        $this->db->unprepared("CREATE TRIGGER reject_synthetic BEFORE INSERT ON TblProspects
            WHEN NEW.Client = 'Failure' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->repository->returned('main', array_map(static fn (array $r): array => ['Record_ID' => $r['Record_ID'], 'Phone' => '2025550101'], $rows), true);
            self::fail('The synthetic storage failure should escape.');
        } catch (QueryException) {
            self::assertSame($before, $this->repository->summary('main'));
            self::assertSame(0, $this->db->table('TblProspects')->count());
            self::assertSame(2, $this->db->table('TblSMSEnrichmentPeople')->whereNotNull('Active_Key')->count());
        }
    }

    public function test_stage_failure_rolls_back_the_batch_chunk_and_all_person_reservations(): void
    {
        $this->db->unprepared("CREATE TRIGGER reject_staged_synthetic BEFORE INSERT ON TblSMSEnrichmentPeople
            WHEN NEW.Client = 'Failure' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->repository->stage('main', [$this->record('One'), $this->record('Failure')], 'operator');
            self::fail('The synthetic ledger failure should escape.');
        } catch (QueryException) {
            foreach (['TblSMSEnrichmentBatches', 'TblSMSEnrichmentChunks', 'TblSMSEnrichmentPeople'] as $table) {
                self::assertSame(0, $this->db->table($table)->count());
            }
        }
    }

    public function test_identity_and_replay_state_survive_repository_recreation(): void
    {
        $source = $this->record('Durable Person');
        $this->repository->stage('main', [$source], 'operator');
        $rows = $this->rows('main');
        $otherInstance = new SmsEnrichmentRepository;
        self::assertSame($rows, iterator_to_array($otherInstance->exportRows('main'), false));
        $summary = $otherInstance->returned('main', [['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '(202) 555-0101']], true);
        self::assertSame($summary, $this->repository->returned('main', [['Record_ID' => $rows[0]['Record_ID'], 'Phone' => '12025550101']], true));
        self::assertSame($summary, $otherInstance->stage('main', [$source], 'operator'));
    }

    public function test_summary_and_recent_contain_only_metadata_and_consistent_counts(): void
    {
        $this->repository->stage('main', [$this->record('Secret Person')], 'secret-actor@example.test');
        $id = $this->rows('main')[0]['Record_ID'];
        $summary = $this->repository->returned('main', [['Record_ID' => $id, 'Phone' => '2025550101', 'Email' => 'secret@example.test']], true);
        $recent = $this->repository->recent();
        self::assertSame([$summary], $recent);
        $json = json_encode($recent, JSON_THROW_ON_ERROR);
        foreach (['Secret Person', '10 Test Way', '2025550101', 'secret@example.test', 'secret-actor@example.test', 'SOURCE-DROP', 'EXT-1'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame($summary['records'], $summary['submitted'] + $summary['existing'] + $summary['duplicate'] + $summary['rejected']);
        self::assertSame($summary['submitted'], $summary['pending'] + $summary['returned'] + $summary['closed_unreturned']);
        self::assertSame($summary['returned'] - $summary['neither'], $summary['phone'] + $summary['email'] - $summary['both']);
    }

    public function test_empty_batches_missing_batches_and_request_bounds_are_explicit(): void
    {
        self::assertSame(0, $this->repository->stage('empty', [], 'operator')['records']);
        self::assertSame('staging', $this->repository->summary('empty')['status'], 'An open zero-row chunk can still accept later chunks.');
        self::assertSame([], $this->rows('empty'));
        self::assertSame('completed', $this->repository->summary('empty')['status'], 'Freezing an empty set must not wait for a provider return.');
        self::assertSame('2026-10-19 18:00:00', $this->repository->summary('empty')['completed_at']);
        self::assertSame('completed', $this->repository->returned('empty', [], true)['status']);
        $this->http(404, fn () => $this->repository->summary('missing'));
        $this->http(404, fn () => $this->repository->exportRows('missing'));
        $this->http(404, fn () => $this->repository->returned('missing', []));
        $this->http(422, fn () => $this->repository->stage('bad/batch', [], 'operator'));
        $this->http(422, fn () => $this->repository->stage('invalid', [], ''));
        $this->http(422, fn () => $this->repository->stage('invalid', [], 'operator', -1));
        $this->http(422, fn () => $this->repository->stage('invalid', ['not-a-list' => []], 'operator'));
        $tooMany = array_fill(0, SmsEnrichmentRepository::MAX_RECORDS + 1, []);
        $this->http(422, fn () => $this->repository->stage('invalid', $tooMany, 'operator'));
        $this->http(422, fn () => $this->repository->returned('empty', $tooMany));
        self::assertSame(1, $this->db->table('TblSMSEnrichmentBatches')->count());
    }

    public function test_fully_excluded_intake_completes_only_when_frozen_and_replays_without_changing_contacts(): void
    {
        $this->db->table('TblProspects')->insert(['Client' => 'Existing', 'Address' => '10 Test Way', 'Zip' => '00123',
            'Phone' => '2025550101', 'Opt_Out' => 1, 'SMS_Attempts' => 4, 'Latest_Export_Date' => '2026-10-07']);
        $before = (array) $this->db->table('TblProspects')->first();
        $this->repository->stage('other', [$this->record('Waiting')], 'operator');
        $records = [$this->record('Existing'), $this->record('Waiting'), $this->record('Invalid', ['Zip' => 'bad'])];
        $summary = $this->repository->stage('excluded', $records, 'operator');
        self::assertSame('staging', $summary['status']);
        self::assertSame([3, 0, 1, 1, 1], $this->counts($summary, ['records','submitted','existing','duplicate','rejected']));
        self::assertSame([], $this->rows('excluded'));
        $summary = $this->repository->summary('excluded');
        self::assertSame('completed', $summary['status']);
        self::assertSame(0, $summary['pending']);
        self::assertSame($summary, $this->repository->stage('excluded', $records, 'operator'));
        $this->http(409, fn () => $this->repository->stage('excluded', [$this->record('Late')], 'operator', 1));
        CarbonImmutable::setTestNow('2026-10-20 18:00:00 UTC');
        self::assertSame([], $this->rows('excluded'));
        self::assertSame($summary, $this->repository->summary('excluded'), 'Re-export must not replace the completion timestamp.');
        self::assertSame($before, (array) $this->db->table('TblProspects')->first());
    }

    public function test_legacy_frozen_zero_member_batches_are_reported_complete_without_get_writes(): void
    {
        $this->repository->stage('legacy-empty', [], 'operator');
        $this->db->table('TblSMSEnrichmentBatches')->where('Batch_ID', 'legacy-empty')->update(['Status' => 'awaiting_return']);
        $before = (array) $this->db->table('TblSMSEnrichmentBatches')->first();
        self::assertSame('completed', $this->repository->summary('legacy-empty')['status']);
        self::assertSame('completed', $this->repository->recent()[0]['status']);
        self::assertSame($before, (array) $this->db->table('TblSMSEnrichmentBatches')->first(), 'Reading old empty jobs does not rewrite their stored evidence.');
    }

    public function test_a_full_chunk_stays_under_sql_server_parameter_limit_and_recent_is_bounded(): void
    {
        $records = [];
        for ($i = 0; $i < SmsEnrichmentRepository::MAX_RECORDS; $i++) $records[] = $this->record('Person '.$i);
        $this->db->enableQueryLog();
        self::assertSame(SmsEnrichmentRepository::MAX_RECORDS, $this->repository->stage('volume', $records, 'operator')['submitted']);
        foreach ($this->db->getQueryLog() as $query) self::assertLessThanOrEqual(2000, count($query['bindings']));
        $this->db->disableQueryLog();
        for ($i = 0; $i < 52; $i++) $this->repository->stage('batch-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), [], 'operator');
        self::assertCount(50, $this->repository->recent());
    }

    public function test_rolling_back_the_dedicated_schema_preserves_existing_prospects_and_sms_tables(): void
    {
        $this->db->table('TblProspects')->insert(['Client' => 'Keep', 'Address' => 'Keep', 'Zip' => '00123']);
        $this->repository->stage('main', [$this->record('New')], 'operator');
        $this->migration->down();
        self::assertSame(1, $this->db->table('TblProspects')->count());
        self::assertTrue($this->db->getSchemaBuilder()->hasTable('TblSMSDrops'));
        foreach (['TblSMSEnrichmentBatches', 'TblSMSEnrichmentChunks', 'TblSMSEnrichmentPeople'] as $table) {
            self::assertFalse($this->db->getSchemaBuilder()->hasTable($table));
        }
    }

    private function record(string $client, array $overrides = []): array
    {
        return array_replace(['Client' => $client, 'Address' => '10 Test Way', 'City' => 'Example', 'State' => 'ca',
            'Zip' => '00123', 'Tier' => 'T2', 'Creation_Date' => '2026-10-12', 'Drop_Name' => 'SOURCE-DROP',
            'External_ID' => 'EXT-1', 'Mailer_PK' => (int) sprintf('%u', crc32(mb_strtolower(trim($client), 'UTF-8'))) + 1], $overrides);
    }

    /** Exercise database-defined equivalence beyond PHP lowercase; no claim about a specific SQL collation's ligatures. */
    private function useEquivalentIdentityCollation(): void
    {
        $key = static fn (string $s): string => str_replace('ß', 'ss', mb_strtolower($s, 'UTF-8'));
        $this->db->getPdo()->sqliteCreateCollation('NOCASE', static fn (string $a, string $b): int => strcmp($key($a), $key($b)));
    }

    private function rows(string $batchId): array
    {
        return iterator_to_array($this->repository->exportRows($batchId), false);
    }

    private function counts(array $summary, array $keys): array
    {
        return array_map(static fn (string $key): int => $summary[$key], $keys);
    }

    private function http(int $status, callable $work): void
    {
        try {
            $work();
            self::fail('Expected an HTTP '.$status.' exception.');
        } catch (HttpException $error) {
            self::assertSame($status, $error->getStatusCode());
        }
    }

    private function assertNoSummaryQueries(): void
    {
        foreach ($this->db->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/\bSUM\s*\(|\bGROUP\s+BY\b/i', $query['query']);
        }
    }
}
