<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncNegotiatorPayrollData;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use PDO;

class SyncNegotiatorPayrollDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(new NullLogger());
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        parent::tearDown();
    }

    private string $lastOutput = '';

    public function test_default_off_preserves_legacy_query_write_order_without_locks_or_transactions(): void
    {
        [$command, $sql, $snowflakes] = $this->command();
        self::assertSame(0, $this->runCommand($command));
        self::assertSame([
            'LDR_READ_1', 'DELETE_LDR', 'INSERT', 'LDR_READ_2', 'DELETE_LDR', 'INSERT', 'LOG',
            'PLAW_READ_1', 'DELETE_PLAW', 'INSERT', 'PLAW_READ_2', 'DELETE_PLAW', 'INSERT', 'LOG',
        ], $sql->events->entries);
        self::assertSame(0, $sql->pdo->begins);
        self::assertSame(0, $sql->pdo->commits);
        self::assertSame(0, $sql->pdo->rollbacks);
        $endDate = new \DateTime('last day of this month');
        $startDate = (clone $endDate)->modify('first day of this month')->modify('-24 months');
        foreach ($snowflakes as $snowflake) {
            self::assertCount(2, $snowflake->queries);
            foreach ($snowflake->queries as $query) {
                self::assertStringContainsString("BETWEEN '" . $startDate->format('Y-m-d') . "' AND '" . $endDate->format('Y-m-d') . "'", $query);
            }
            self::assertStringContainsString("t.STATUS IN(0, 1, 4)", $snowflake->queries[0]);
            self::assertStringContainsString("so.OFFER_STATUS = 10", $snowflake->queries[0]);
            self::assertStringContainsString("so.CREATED_AT", $snowflake->queries[1]);
        }
        $deletes = array_values(array_filter($sql->calls, static fn (array $call): bool => str_starts_with(ltrim($call['sql']), 'DELETE')));
        self::assertCount(4, $deletes);
        self::assertStringContainsString("Source IN ('DP_LDR', 'LDR', 'ldr')", $deletes[0]['sql']);
        self::assertStringContainsString("Source IN ('DP_PLAW', 'PLAW', 'ProLaw', 'prolaw')", $deletes[2]['sql']);
    }

    public function test_legacy_source_metadata_and_sparse_rows_remain_accepted_without_validation(): void
    {
        $ldr = new NegotiatorPayrollSnowflake([
            ['data' => [['CONTACT_NAME' => "O'Neil"]]],
            ['success' => true, 'data' => [$this->settlementRow()], 'rowCount' => 999],
        ]);
        [$command, $sql] = $this->command($ldr);
        self::assertSame(0, $this->runCommand($command));
        self::assertCount(10, $sql->calls);
        self::assertStringContainsString("'O''Neil', '', '0', '', '', NULL, '', 'DP_LDR'", $sql->calls[1]['sql']);
        self::assertStringContainsString('LDR negotiator payroll data synced successfully.', $this->lastOutput);
    }

    public function test_legacy_failed_source_results_keep_empty_data_fallback_and_success_exit(): void
    {
        $ldr = new NegotiatorPayrollSnowflake([['success' => false], ['success' => false, 'data' => []]]);
        [$command, $sql] = $this->command($ldr);
        self::assertSame(0, $this->runCommand($command));
        self::assertCount(4, array_filter($sql->calls, static fn (array $call): bool => str_starts_with(ltrim($call['sql']), 'DELETE')));
        self::assertStringContainsString('LDR EPF Summary rows: 0', $this->lastOutput);
        self::assertStringContainsString('LDR Settlement Summary rows: 0', $this->lastOutput);
    }

    public function test_legacy_failed_delete_still_inserts_and_processes_other_source(): void
    {
        [$command, $sql] = $this->command();
        $sql->failWhen = 'DELETE FROM TblNegotiatorEPFSummary';
        self::assertSame(0, $this->runCommand($command));
        self::assertCount(10, $sql->calls);
        self::assertStringContainsString('Failed to delete EPF Summary: synthetic SQL failure', $this->lastOutput);
        self::assertStringContainsString('LDR negotiator payroll data synced successfully.', $this->lastOutput);
        self::assertSame(0, $sql->pdo->rollbacks);
    }

    public function test_legacy_insert_failure_is_not_rolled_back_and_does_not_change_overall_success(): void
    {
        [$command, $sql] = $this->command();
        $sql->failWhen = 'INSERT INTO TblNegotiatorSettlementSummary';
        self::assertSame(0, $this->runCommand($command));
        self::assertStringContainsString('LDR sync failed: Settlement Summary insert failed: synthetic SQL failure', $this->lastOutput);
        self::assertStringContainsString('PLAW negotiator payroll data synced successfully.', $this->lastOutput);
        self::assertCount(9, $sql->calls);
        self::assertSame(0, $sql->pdo->rollbacks);
    }

    public function test_legacy_log_unsuccessful_result_keeps_original_success_message(): void
    {
        [$command, $sql] = $this->command();
        $sql->failWhen = "COL_LENGTH('dbo.TblLog'";
        self::assertSame(0, $this->runCommand($command));
        self::assertStringContainsString('Log entry inserted into TblLog for LDR.', $this->lastOutput);
    }

    public function test_evidence_hook_receives_scope_after_both_legacy_sources_finish(): void
    {
        $calls = [];
        [$command, $sql] = $this->command(evidenceHook: function (DBConnector $sqlServer, DBConnector $snowflake, string $source, string $period, ?string $payoutDate) use (&$calls): void {
            self::assertSame(10, count($sqlServer->calls));
            self::assertSame(0, $sqlServer->pdo->begins);
            $calls[] = [$source, $period, $payoutDate];
        });
        self::assertSame(0, $this->runCommand($command, ['--period' => '2026-08', '--payout-date' => '2026-09-15']));
        self::assertSame([['LDR', '2026-08', '2026-09-15'], ['PLAW', '2026-08', '2026-09-15']], $calls);
    }

    public function test_evidence_failure_leaves_original_writes_and_continues_other_evidence_company(): void
    {
        $calls = [];
        [$command, $sql] = $this->command(evidenceHook: static function (DBConnector $sqlServer, DBConnector $snowflake, string $source) use (&$calls): void {
            $calls[] = $source;
            if ($source === 'LDR') throw new \RuntimeException('synthetic NGO evidence failure');
        });
        self::assertSame(1, $this->runCommand($command));
        self::assertSame(['LDR', 'PLAW'], $calls);
        self::assertCount(10, $sql->calls);
        self::assertSame(0, $sql->pdo->rollbacks);
        self::assertStringContainsString('LDR negotiator payroll data synced successfully.', $this->lastOutput);
        self::assertStringContainsString('PLAW negotiator payroll data synced successfully.', $this->lastOutput);
    }

    public function test_evidence_runs_only_after_nonthrowing_legacy_company(): void
    {
        $calls = [];
        [$command, $sql] = $this->command(evidenceHook: static function (DBConnector $sqlServer, DBConnector $snowflake, string $source) use (&$calls): void { $calls[] = $source; });
        $sql->failWhen = 'INSERT INTO TblNegotiatorEPFSummary';
        self::assertSame(0, $this->runCommand($command));
        self::assertSame(['PLAW'], $calls);
    }

    public function test_default_off_never_resolves_writer_or_new_tables_even_with_invalid_new_options(): void
    {
        [$command, $sql] = $this->command();
        $app = new \Illuminate\Container\Container();
        $app->instance('config', new \Illuminate\Config\Repository([]));
        $app->bind(\Cmd\Reports\Contracts\NgoPayrollEvidenceSync::class, static function (): never { throw new \LogicException('Disabled evidence factory must never resolve.'); });
        $command->setLaravel($app);
        self::assertSame(0, $this->runCommand($command, ['--period' => 'invalid', '--payout-date' => 'invalid']));
        self::assertCount(10, $sql->calls);
        self::assertSame(0, $sql->pdo->begins);
        foreach ($sql->calls as $call) self::assertStringNotContainsString('TblNGOPayrollEvidence', $call['sql']);
    }

    public function test_invalid_enabled_evidence_scope_fails_only_new_stage_after_original_writes(): void
    {
        foreach ([
            ['--period' => '0000-10'],
            ['--period' => '2026-13'],
            ['--payout-date' => '2026-10-01'],
            ['--period' => '2026-08', '--payout-date' => '2026-08-31'],
            ['--period' => '2026-08', '--payout-date' => '2026-09-31'],
        ] as $options) {
            [$command, $sql] = $this->command(evidenceHook: static function (): never { self::fail('Invalid evidence scope must not invoke importer.'); });
            self::assertSame(1, $this->runCommand($command, $options));
            self::assertCount(10, $sql->calls);
            self::assertStringContainsString('PLAW negotiator payroll data synced successfully.', $this->lastOutput);
        }
    }

    private function command(?NegotiatorPayrollSnowflake $ldr = null, ?NegotiatorPayrollSnowflake $plaw = null, ?\Closure $evidenceHook = null): array
    {
        $ldr ??= new NegotiatorPayrollSnowflake([$this->validEpfResult(), $this->validSettlementResult()]);
        $plaw ??= new NegotiatorPayrollSnowflake([$this->validEpfResult(), $this->validSettlementResult()]);
        $events = new NegotiatorPayrollEvents();
        $sql = new NegotiatorPayrollSqlServer($events);
        $ldr->events = $events;
        $ldr->source = 'LDR';
        $plaw->events = $events;
        $plaw->source = 'PLAW';
        $command = new class($sql, $ldr, $plaw, $evidenceHook) extends SyncNegotiatorPayrollData {
            public function __construct(private DBConnector $sql, private DBConnector $ldr, private DBConnector $plaw, private ?\Closure $evidenceHook)
            {
                parent::__construct();
            }

            protected function initializeSqlServerConnector(): DBConnector { return $this->sql; }
            protected function ngoEvidenceEnabled(): bool { return $this->evidenceHook !== null || parent::ngoEvidenceEnabled(); }
            protected function initializeSnowflakeConnector(string $environment): DBConnector
            {
                return $environment === 'ldr' ? $this->ldr : $this->plaw;
            }
            protected function syncNgoEvidence(DBConnector $sqlServer, DBConnector $snowflake, string $source, string $period, ?string $payoutDate): void
            {
                if ($this->evidenceHook !== null) {
                    ($this->evidenceHook)($sqlServer, $snowflake, $source, $period, $payoutDate);
                    return;
                }
                parent::syncNgoEvidence($sqlServer, $snowflake, $source, $period, $payoutDate);
            }
        };

        return [$command, $sql, ['ldr' => $ldr, 'plaw' => $plaw]];
    }

    private function runCommand(SyncNegotiatorPayrollData $command, array $options = []): int
    {
        $input = new ArrayInput($options, $command->getDefinition());
        $command->setInput($input);
        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle($input, $output));
        $result = $command->handle();
        $this->lastOutput = $output->fetch();
        return $result;
    }

    private function validEpfResult(): array
    {
        return ['success' => true, 'data' => [[
            'CONTACT_NAME' => 'Synthetic Contact', 'CONTACT_ID' => 1, 'COLLECTED' => 50,
            'SETTLEMENT_ID' => 2, 'MEMO' => 'Synthetic Creditor', 'SDATE' => '2098-01-01', 'NEG_ID' => 3,
        ]], 'rowCount' => 1];
    }

    private function validSettlementResult(): array
    {
        return ['success' => true, 'data' => [$this->settlementRow()], 'rowCount' => 1];
    }

    private function settlementRow(): array
    {
        return [
            'CONTACT_NAME' => 'Synthetic Contact', 'LLG_ID' => 1, 'ORIGINAL_DEBT_AMOUNT' => 100,
            'SETTLEMENT_ID' => 2, 'CREDITOR_NAME' => 'Synthetic Creditor', 'COLLECTION_COMPANY' => 'Synthetic Buyer',
            'SETTLEMENT_DATE' => '2098-01-01', 'NEG_ID' => 3, 'CREATED_AT' => '2098-01-01',
            'SETTLEMENT_AMOUNT' => 75, 'ID' => 4,
        ];
    }
}

final class NegotiatorPayrollPDO extends PDO
{
    private bool $transaction = false;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct() {}
    public function beginTransaction(): bool { $this->begins++; $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->commits++; $this->transaction = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->transaction = false; return true; }
}

final class NegotiatorPayrollEvents
{
    public array $entries = [];
}

final class NegotiatorPayrollSqlServer extends DBConnector
{
    public NegotiatorPayrollPDO $pdo;
    public array $calls = [];
    public ?string $failWhen = null;
    private string $source = '';

    public function __construct(public NegotiatorPayrollEvents $events) { $this->pdo = new NegotiatorPayrollPDO(); }
    public function getSqlServerConnection(): PDO { return $this->pdo; }
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'sp_getapplock')) {
            $this->events->entries[] = 'LOCK';
            $this->source = substr((string) ($params[0] ?? ''), strrpos((string) ($params[0] ?? ''), ':') + 1);
        } elseif (preg_match('/^\s*DELETE/i', $sql)) {
            $this->events->entries[] = 'DELETE_' . (str_contains($sql, 'DP_PLAW') ? 'PLAW' : 'LDR');
        } elseif (str_contains($sql, "COL_LENGTH('dbo.TblLog'")) {
            $this->events->entries[] = 'LOG';
        } elseif (preg_match('/^\s*INSERT/i', $sql)) {
            $this->events->entries[] = 'INSERT';
        }
        if ($this->failWhen !== null && str_contains($sql, $this->failWhen)) {
            $this->failWhen = null;
            return ['success' => false, 'error' => 'synthetic SQL failure'];
        }
        return ['success' => true, 'data' => []];
    }
}

final class NegotiatorPayrollSnowflake extends DBConnector
{
    public array $queries = [];
    public ?NegotiatorPayrollEvents $events = null;
    public string $source = '';

    public function __construct(private array $results) {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->queries[] = $sql;
        if ($this->events !== null) {
            $this->events->entries[] = $this->source . '_READ_' . count($this->queries);
        }
        return array_shift($this->results) ?? ['success' => false, 'data' => [], 'rowCount' => 0];
    }
}
