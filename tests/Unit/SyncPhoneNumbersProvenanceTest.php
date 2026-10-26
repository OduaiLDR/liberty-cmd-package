<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\ContactPhoneSource;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncPhoneNumbersProvenanceTest extends TestCase
{
    private Container $previous;

    private Container $app;

    private Connection $db;

    private int $invalidations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->app = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $logger = Mockery::mock();
        $logger->shouldReceive('info', 'error', 'warning')->andReturnNull();
        Log::swap($logger);
        $availability = Mockery::mock(SmsAvailabilityService::class);
        $availability->shouldReceive('invalidate')->andReturnUsing(function (): void {
            $this->invalidations++;
        });
        $this->app->instance(SmsAvailabilityService::class, $availability);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->statement('CREATE TABLE TblPhoneNumbers (Phone TEXT, Source TEXT, CID TEXT, Created_Date TEXT)');
        foreach (['DP', 'DP_LDR', 'DP_PLAW', 'DP_LT'] as $source) {
            $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550000', 'Source' => $source, 'CID' => '99', 'Created_Date' => null]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
        parent::tearDown();
    }

    private function row(string $id = '123', string $date = '2026-10-14', string $phone = '202-555-0199'): array
    {
        return ['ID' => $id, 'PHONE_SLOT' => '0', 'PHONE' => $phone, 'CREATED_DATE' => $date];
    }

    private function summary(array $overrides = []): array
    {
        return array_replace(['SOURCE_ROWS' => '1', 'NORMALIZED_ROWS' => '1', 'CONTACTS' => '1', 'INVALID_IDS' => '0', 'MISSING_CREATED' => '0', 'MAX_CID' => '123'], $overrides);
    }

    private function connector(array $row, array $summary = []): DBConnector
    {
        $source = Mockery::mock(DBConnector::class);
        $summary = $this->summary(array_replace(['MAX_CID' => $row['ID']], $summary));
        $source->shouldReceive('queryReadOnly')->andReturnUsing(function (string $sql) use ($row, $summary): array {
            return ['data' => str_contains($sql, 'AS SOURCE_ROWS') ? [$summary] : [$row]];
        });

        return $source;
    }

    private function command(array $sources): SyncPhoneNumbers
    {
        $command = new class($sources, $this->db->getPdo()) extends SyncPhoneNumbers
        {
            public array $requested = [];

            public int $targetConnections = 0;

            public int $flagged = 0;

            public function __construct(private array $sources, private PDO $pdo)
            {
                parent::__construct();
            }

            protected function snowflakeConnector(string $environment): DBConnector
            {
                $this->requested[] = $environment;

                return $this->sources[$environment];
            }

            protected function initializeSqlServerConnection(): PDO
            {
                $this->targetConnections++;

                return $this->pdo;
            }

            protected function flagContactedProspects(): ?int
            {
                $this->flagged++;

                return 0;
            }
        };
        $command->setLaravel($this->app);

        return $command;
    }

    public function test_default_dry_run_validates_all_three_sources_without_opening_the_target(): void
    {
        $source = $this->connector($this->row());
        $command = $this->command(['ldr' => $source, 'plaw' => $source, 'lt' => $source]);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertSame(['ldr', 'plaw', 'lt'], $command->requested);
        self::assertSame(0, $command->targetConnections);
        self::assertSame(0, $command->flagged);
        self::assertSame(4, $this->db->table('TblPhoneNumbers')->count());
        foreach (['DP_LDR', 'DP_PLAW', 'DP_LT'] as $label) {
            self::assertStringContainsString('"source":"'.$label.'"', $tester->getDisplay());
        }
    }

    public function test_source_selector_is_case_insensitive_and_does_not_guess_legacy_dp(): void
    {
        self::assertSame(['ldr' => 'DP_LDR'], ContactPhoneSource::sources(' LDR '));
        $this->expectException(RuntimeException::class);
        ContactPhoneSource::sources('DP');
    }

    public function test_invalid_options_fail_before_connecting_to_a_database(): void
    {
        foreach ([['--source' => 'DP'], ['--verify-target' => true]] as $options) {
            $command = $this->command([]);
            self::assertSame(1, (new CommandTester($command))->execute($options));
            self::assertSame(0, $command->targetConnections);
            self::assertSame([], $command->requested);
        }
    }

    public function test_single_source_apply_preserves_other_sources(): void
    {
        $command = $this->command(['ldr' => $this->connector($this->row())]);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--source' => 'LDR']), $tester->getDisplay());
        self::assertSame(['ldr'], $command->requested);
        self::assertSame('123', $this->db->table('TblPhoneNumbers')->where('Source', 'DP_LDR')->value('CID'));
        foreach (['DP', 'DP_LT', 'DP_PLAW'] as $source) {
            self::assertSame('99', $this->db->table('TblPhoneNumbers')->where('Source', $source)->value('CID'));
        }
    }

    public function test_staged_row_count_mismatch_rolls_back_the_source(): void
    {
        $command = $this->command([]);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true, '--source' => 'invalid']); // Initializes command output only.
        (new \ReflectionProperty($command, 'withCreatedDate'))->setValue($command, true);
        $path = tempnam(sys_get_temp_dir(), 'phone-count-test-');
        file_put_contents($path, json_encode(['phone' => '2025550199', 'cid' => '123', 'created' => '2026-10-14']).PHP_EOL);
        try {
            try {
                (new \ReflectionMethod($command, 'replacePhonesFromFile'))->invoke($command, $this->db->getPdo(), $path, 1000, 2);
                self::fail('Incomplete staging file was accepted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Staged phone row count differs', $error->getMessage());
            }
            self::assertSame('99', $this->db->table('TblPhoneNumbers')->where('Source', 'DP_LT')->value('CID'));
            self::assertSame(4, $this->db->table('TblPhoneNumbers')->count());
            self::assertSame(0, $this->invalidations);
        } finally {
            unlink($path);
        }
    }

    public function test_apply_stores_exact_ids_and_dates_for_each_source_and_preserves_legacy_rows(): void
    {
        $largeId = '123456789012345678901234';
        $command = $this->command(['ldr' => $this->connector($this->row($largeId)), 'plaw' => $this->connector($this->row('124')), 'lt' => $this->connector($this->row('125'))]);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame($largeId, $this->db->table('TblPhoneNumbers')->where('Source', 'DP_LDR')->value('CID'));
        foreach (['DP_LDR' => '123456789012345678901234', 'DP_PLAW' => '124', 'DP_LT' => '125'] as $source => $id) {
            $row = $this->db->table('TblPhoneNumbers')->where('Source', $source)->first();
            self::assertSame($id, $row->CID);
            self::assertSame('2026-10-14', $row->Created_Date);
            self::assertSame('2025550199', $row->Phone);
        }
        self::assertNull($this->db->table('TblPhoneNumbers')->where('Source', 'DP')->value('Created_Date'));
        self::assertSame(4, $this->db->table('TblPhoneNumbers')->count());
        self::assertSame(1, $command->flagged);
        self::assertSame(3, $this->invalidations);
    }

    public function test_later_source_validation_failure_preserves_every_existing_source(): void
    {
        $before = $this->db->table('TblPhoneNumbers')->orderBy('Source')->get()->toArray();
        $command = $this->command(['ldr' => $this->connector($this->row()), 'plaw' => $this->connector($this->row('124', '2026-02-30'))]);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('invalid contact creation date', $tester->getDisplay());
        self::assertEquals($before, $this->db->table('TblPhoneNumbers')->orderBy('Source')->get()->toArray());
        self::assertSame(0, $this->invalidations);
        self::assertSame(0, $command->flagged);
    }

    public function test_database_failure_rolls_back_that_source_and_does_not_touch_later_sources(): void
    {
        $this->db->statement("CREATE TRIGGER phone_failure BEFORE INSERT ON TblPhoneNumbers WHEN NEW.Source='DP_PLAW' BEGIN SELECT RAISE(ABORT,'synthetic database failure'); END");
        $source = $this->connector($this->row());
        $command = $this->command(['ldr' => $source, 'plaw' => $source, 'lt' => $source]);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute([]));
        self::assertSame('123', $this->db->table('TblPhoneNumbers')->where('Source', 'DP_LDR')->value('CID'));
        self::assertSame('99', $this->db->table('TblPhoneNumbers')->where('Source', 'DP_PLAW')->value('CID'));
        self::assertSame('99', $this->db->table('TblPhoneNumbers')->where('Source', 'DP_LT')->value('CID'));
        self::assertSame(1, $this->invalidations);
        self::assertSame(0, $command->flagged);
    }

    public function test_missing_created_column_stops_before_reading_or_replacing_sources(): void
    {
        $this->db->statement('ALTER TABLE TblPhoneNumbers DROP COLUMN Created_Date');
        $command = $this->command([]);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Created_Date is required', $tester->getDisplay());
        self::assertSame([], $command->requested);
        self::assertSame(4, $this->db->table('TblPhoneNumbers')->count());
    }

    public function test_missing_upstream_id_fails_the_read_only_preview(): void
    {
        $source = $this->connector($this->row(), ['INVALID_IDS' => '1']);
        $command = $this->command(['ldr' => $source]);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute(['--dry-run' => true, '--source' => 'ldr']));
        self::assertSame(0, $command->targetConnections);
        self::assertSame(0, $command->flagged);
    }

    public function test_normalization_preserves_country_prefix_and_rejects_missing_or_imprecise_ids(): void
    {
        $reader = new ContactPhoneSource;
        self::assertSame(['phone' => '12025550199', 'cid' => '123', 'created' => '2026-10-14'], $reader->normalize($this->row('123', '2026-10-14', '+1 (202) 555-0199')));
        self::assertNull($reader->normalize(['PHONE' => '---']));
        foreach ([null, '', 0, -1, 1.5, '12.3', '12 OR 1=1'] as $id) {
            try {
                $reader->normalize(array_replace($this->row(), ['ID' => $id]));
                self::fail('Invalid ID was accepted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('invalid contact ID', $error->getMessage());
            }
        }
    }

    public function test_empty_or_changed_page_counts_cannot_produce_a_replacement_snapshot(): void
    {
        $reader = new ContactPhoneSource;
        $empty = $this->connector($this->row(), ['NORMALIZED_ROWS' => '0', 'CONTACTS' => '0']);
        try {
            $reader->stage($empty);
            self::fail('Empty source was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('empty', $error->getMessage());
        }
        $source = $this->connector($this->row(), ['SOURCE_ROWS' => '2', 'NORMALIZED_ROWS' => '2']);
        $this->expectExceptionMessage('changed or pagination is incomplete');
        $reader->stage($source);
    }

    public function test_pagination_is_keyset_based_and_deleted_rows_are_filtered_explicitly(): void
    {
        $source = Mockery::mock(DBConnector::class);
        $queries = [];
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = $this->row((string) $i);
        }
        $summary = $this->summary(['SOURCE_ROWS' => '5001', 'NORMALIZED_ROWS' => '5001', 'CONTACTS' => '5000', 'MAX_CID' => '5000']);
        $pages = 0;
        $source->shouldReceive('queryReadOnly')->andReturnUsing(function (string $sql) use (&$queries, &$pages, $rows, $summary): array {
            $queries[] = $sql;
            if (str_contains($sql, 'AS SOURCE_ROWS')) {
                return ['data' => [$summary]];
            }

            return ['data' => $pages++ === 0 ? $rows : [array_replace($this->row('5000'), ['PHONE_SLOT' => '1'])]];
        });
        $snapshot = (new ContactPhoneSource)->stage($source);
        try {
            self::assertSame(5001, $snapshot['rows']);
            self::assertSame(5000, $snapshot['contacts']);
            self::assertSame($snapshot['sha256'], hash_file('sha256', $snapshot['path']));
            self::assertStringContainsString('c.ID > 5000', $queries[2]);
            self::assertStringContainsString('c.ID = 5000 AND phone_values.INDEX > 0', $queries[2]);
            foreach ($queries as $sql) {
                self::assertStringNotContainsString('OFFSET', $sql);
                self::assertStringContainsString('c._FIVETRAN_DELETED = FALSE OR c._FIVETRAN_DELETED IS NULL', $sql);
            }
        } finally {
            unlink($snapshot['path']);
        }
    }
}
