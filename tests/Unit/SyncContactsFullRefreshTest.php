<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__, 2) . '/src/Console/Commands/SyncContactsData.php';

class SyncContactsFullRefreshTest extends TestCase
{
    private function property(object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty(SyncContactsData::class, $name))->setValue($object, $value);
    }

    private function runRefresh(string $failure = ''): array
    {
        $events = [];
        $targetRows = 7;
        $stageRows = 0;
        $inTransaction = false;
        $snapshot = 7;
        $pdo = $this->getMockBuilder(\PDO::class)->disableOriginalConstructor()
            ->onlyMethods(['exec', 'query', 'beginTransaction', 'commit', 'rollBack', 'inTransaction', 'errorInfo'])->getMock();
        $pdo->method('errorInfo')->willReturn(['HY000', 1, 'simulated SQL failure']);
        // Use references: arrow functions capture scalar values by value.
        $pdo->method('inTransaction')->willReturnCallback(function () use (&$inTransaction) { return $inTransaction; });
        $pdo->method('beginTransaction')->willReturnCallback(function () use (&$events, &$inTransaction, &$snapshot, &$targetRows) {
            self::assertFalse($inTransaction);
            $events[] = 'begin';
            $snapshot = $targetRows;
            $inTransaction = true;
            return true;
        });
        $pdo->method('commit')->willReturnCallback(function () use (&$events, &$inTransaction) {
            $events[] = 'commit';
            $inTransaction = false;
            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(function () use (&$events, &$inTransaction, &$targetRows, &$snapshot) {
            $events[] = 'rollback';
            $targetRows = $snapshot;
            $inTransaction = false;
            return true;
        });
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$events, &$targetRows, &$stageRows, &$inTransaction, $failure) {
            $events[] = $sql;
            self::assertStringNotContainsString('TRUNCATE', $sql);
            if (str_starts_with($sql, 'INSERT INTO #ContactsRefresh_')) {
                if ($failure === 'stage') { return false; }
                $stageRows++;
            }
            if (str_starts_with($sql, 'DELETE FROM TblContactsLDR')) {
                self::assertTrue($inTransaction);
                $targetRows = 0;
            }
            if (str_starts_with($sql, 'INSERT INTO TblContactsLDR')) {
                self::assertTrue($inTransaction);
                if ($failure === 'replace') { return false; }
                $targetRows = $stageRows;
            }
            return 1;
        });
        $pdo->method('query')->willReturnCallback(function (string $sql) use (&$events, &$stageRows, &$targetRows, $failure) {
            $events[] = $sql;
            $statement = $this->getMockBuilder(\PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['fetchColumn'])->getMock();
            $isStage = str_contains($sql, '#ContactsRefresh_');
            $count = $isStage ? $stageRows : $targetRows;
            if (($failure === 'stage_count' && $isStage) || ($failure === 'target_count' && !$isStage)) { $count++; }
            $statement->method('fetchColumn')->willReturn($count);
            return $statement;
        });
        $snowflake = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $page = 0;
        $snowflake->method('query')->willReturnCallback(function (string $sql) use (&$page, &$events, $failure) {
            $page++;
            $events[] = 'fetch:' . $page;
            self::assertStringContainsString('LIMIT 5000', $sql);
            if (($failure === 'first_fetch' && $page === 1) || ($failure === 'later_fetch' && $page === 2)) {
                throw new \RuntimeException('simulated Snowflake timeout');
            }
            if ($failure === 'malformed') { return ['success' => false]; }
            if ($failure === 'incomplete') { return ['data' => [], 'rowCount' => 1]; }
            if ($failure === 'empty' || $page > 2) { return ['data' => []]; }
            return ['data' => [['LLG_ID' => ($failure === 'cursor' ? 1 : $page), 'DEBT_AMOUNT_CUSTOM' => null,
                'ENROLLED_DEBT' => 13920, 'EXTERNAL_ID' => '1234567890' . $page]]];
        });
        $sql = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()
            ->onlyMethods(['querySqlServer', 'getSqlServerConnection'])->getMock();
        $sql->method('getSqlServerConnection')->willReturn($pdo);
        $sql->method('querySqlServer')->willReturnCallback(function (string $query) use (&$events) {
            $events[] = $query;
            self::assertStringNotContainsString('TRUNCATE', $query);
            self::assertStringNotContainsString('DELETE FROM TblContactsLDR', $query);
            return ['success' => true, 'data' => []];
        });
        $command = $this->getMockBuilder(SyncContactsData::class)
            ->onlyMethods(['initializeSnowflakeConnector', 'initializeSqlServerConnector'])->getMock();
        $command->method('initializeSnowflakeConnector')->willReturn($snowflake);
        $command->method('initializeSqlServerConnector')->willReturn($sql);
        $this->property($command, 'source', 'LDR');
        $this->property($command, 'targetTable', 'TblContactsLDR');
        $this->property($command, 'debtAmountCustomId', 745839);
        $this->property($command, 'agentCustomId', 1);
        // Route watermark writes into an isolated local test directory.
        $input = new ArrayInput(['--source' => 'LDR', '--full' => true, '--no-match' => true], $command->getDefinition());
        $output = new BufferedOutput();
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        $storage = sys_get_temp_dir() . '/contacts-refresh-test-' . bin2hex(random_bytes(6));
        mkdir($storage . '/app', 0700, true);
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $app->useStoragePath($storage);
        $app->instance('log', new \Psr\Log\NullLogger());
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        try {
            $status = (new \ReflectionMethod(SyncContactsData::class, 'runSourceSync'))->invoke($command);
            $watermark = file_exists($storage . '/app/sync_timestamps.json');
        } finally {
            @unlink($storage . '/app/sync_timestamps.json');
            rmdir($storage . '/app');
            rmdir($storage);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        }
        return [$status, $targetRows, $events, $watermark, $output->fetch()];
    }

    public function test_first_and_later_fetch_failures_preserve_existing_contacts(): void
    {
        foreach (['first_fetch', 'later_fetch', 'empty', 'stage_count', 'cursor', 'stage', 'malformed', 'incomplete'] as $failure) {
            [$status, $rows, $events, $watermark, $output] = $this->runRefresh($failure);
            self::assertSame(1, $status, $failure . ': ' . $output);
            self::assertSame(7, $rows, $failure);
            self::assertFalse($watermark, $failure);
            self::assertFalse((bool) array_filter($events, fn($event) => str_starts_with($event, 'DELETE FROM TblContactsLDR')), $failure);
        }
    }

    public function test_replacement_failures_roll_back_old_contacts(): void
    {
        foreach (['replace', 'target_count'] as $failure) {
            [$status, $rows, $events, $watermark, $output] = $this->runRefresh($failure);
            self::assertSame(1, $status, $output);
            self::assertSame(7, $rows);
            self::assertContains('rollback', $events);
            self::assertFalse($watermark);
        }
    }

    public function test_success_publishes_only_after_all_pages_including_empty_final_page(): void
    {
        [$status, $rows, $events, $watermark, $output] = $this->runRefresh();
        self::assertSame(0, $status, $output);
        self::assertSame(2, $rows);
        self::assertTrue($watermark);
        $delete = array_search('DELETE FROM TblContactsLDR WITH (TABLOCKX)', $events, true);
        self::assertIsInt($delete);
        self::assertGreaterThan(array_search('fetch:3', $events, true), $delete);
        self::assertSame('begin', $events[$delete - 1]);
        self::assertStringContainsString('Committed 2 contacts', $output);
    }

    public function test_lt_bounds_contacts_before_history_joins_and_preserves_status_eligibility(): void
    {
        $command = new SyncContactsData();
        $this->property($command, 'debtAmountCustomId', 595171);
        $sql = (new \ReflectionMethod($command, 'buildLTQuery'))->invoke($command, '2021-07-01', 100, 5000);
        self::assertStringContainsString('WITH contact_page AS', $sql);
        self::assertStringContainsString('eligible_lead.TITLE <> \'Duplicate Lead\'', $sql);
        self::assertStringContainsString('AND c.ID > 100', $sql);
        self::assertLessThan(strpos($sql, 'LEFT JOIN CONTACTS_ASSIGNED'), strpos($sql, 'LIMIT 5000'));
        self::assertStringContainsString('FROM contact_page AS c', $sql);
    }
}
