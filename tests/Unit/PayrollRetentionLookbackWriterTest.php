<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\GenerateRetentionBonusCommission;
use Cmd\Reports\Services\PayrollRetentionLookbackWriter;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PDO;
use Psr\Log\NullLogger;

class PayrollRetentionLookbackWriterTest extends TestCase
{
    private const RUN_ID = '40fa42ab6e184576aa34ccd880b45529';

    public function test_calculated_sales_deductions_publish_canonical_identity_and_completed_run(): void
    {
        $sql = new LookbackFeedConnector;
        $prepared = PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, [
            ['ID' => '101', 'AGENT' => ' ALICE SALES ', 'AGENT_DEDUCTION' => 125.25],
            ['ID' => '102', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => ''],
            ['ID' => '103', 'AGENT' => null, 'AGENT_DEDUCTION' => 0],
        ]);
        $this->assertSame(0, $sql->writes, 'Identity preparation is read only.');
        $this->assertSame(125.25, $prepared['rows'][0]['Agent_Deduction'], 'Use the existing calculated deduction without another formula.');
        $this->assertSame(701, $prepared['rows'][0]['Employee_PK']);
        $this->assertSame('Alice Sales', $prepared['rows'][0]['Agent']);
        $this->assertSame('LLG-101', $prepared['rows'][0]['CID']);
        $this->assertNull($prepared['rows'][1]['Agent_Deduction']);
        $this->assertSame('default_rate_no_deduction', $prepared['rows'][1]['Exclusion_Reason']);
        $this->assertNull($prepared['rows'][2]['Exclusion_Reason']);
        $this->assertSame(2, $prepared['deductionRowCount']);
        $this->assertSame(1, $prepared['exclusionRowCount']);
        $result = PayrollRetentionLookbackWriter::publish($sql, $prepared);
        $this->assertSame(self::RUN_ID, $result['runId']);
        $this->assertSame(3, $result['rowCount']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['payloadSha256']);
        $this->assertSame(1, $sql->pdo->commits);
        $this->assertSame(3, count($sql->pdo->rows['ldr']));
        $this->assertSame(self::RUN_ID, $sql->pdo->markers['ldr']['runId']);
        $this->assertSame('older-plaw', $sql->pdo->markers['plaw']['runId'], 'One source cannot replace its sibling.');
    }

    public function test_verified_empty_source_replaces_stale_rows_and_writes_zero_work_marker(): void
    {
        $sql = new LookbackFeedConnector;
        PayrollRetentionLookbackWriter::publish($sql, PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, []));
        $this->assertSame([], $sql->pdo->rows['ldr']);
        $this->assertSame(0, $sql->pdo->markers['ldr']['rowCount']);
        $this->assertSame(self::RUN_ID, $sql->pdo->markers['ldr']['runId']);
        $this->assertSame(1, $sql->pdo->commits);
    }

    public function test_both_source_completion_markers_share_exact_parent_run_and_period(): void
    {
        $sql = new LookbackFeedConnector;
        foreach (['ldr', 'plaw'] as $source) {
            $result = PayrollRetentionLookbackWriter::publish($sql, PayrollRetentionLookbackWriter::prepare($sql, $source, '2098-09-01', self::RUN_ID, []));
            $this->assertSame($source, $result['source']);
            $this->assertSame('2098-09-01', $result['periodStart']);
            $this->assertSame(0, $result['rowCount']);
            $this->assertSame(0, $result['deductionRowCount']);
            $this->assertSame(0, $result['exclusionRowCount']);
            $this->assertSame(self::RUN_ID, $sql->pdo->markers[$source]['runId']);
        }
        $this->assertSame(2, $sql->pdo->commits);
    }

    public function test_any_failed_row_or_marker_rolls_back_the_whole_source_replacement(): void
    {
        foreach (['row', 'marker', 'commit'] as $failure) {
            $sql = new LookbackFeedConnector;
            $prepared = PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, [
                ['ID' => '101', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => 125.25],
            ]);
            $before = [$sql->pdo->rows, $sql->pdo->markers];
            $sql->failure = $failure;
            try {
                PayrollRetentionLookbackWriter::publish($sql, $prepared);
                $this->fail('An incomplete source must not receive a completion marker.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Lookback deduction feed', $error->getMessage());
            }
            $this->assertSame($before, [$sql->pdo->rows, $sql->pdo->markers]);
            $this->assertSame(1, $sql->pdo->rollbacks);
        }
    }

    public function test_bonus_and_feed_must_both_succeed_before_publication_returns_success(): void
    {
        Log::swap(new NullLogger);
        foreach (['', 'bonus', 'marker'] as $failure) {
            $sql = new LookbackFeedConnector;
            $sql->failure = $failure;
            try {
                $result = PayrollRetentionLookbackWriter::replaceCalculatedResults($sql, 'ldr', '2098-09-01', self::RUN_ID,
                    [['ID' => '101', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => 125.25]],
                    [['agent' => 'Retention Earner', 'amount' => 80.50]]);
                $this->assertSame('', $failure);
                $this->assertSame(['attempted' => 1, 'written' => 1, 'failed' => 0], $result);
                $this->assertSame(80.50, $sql->pdo->bonusRows['Retention Earner']);
                $this->assertSame(self::RUN_ID, $sql->pdo->markers['ldr']['runId']);
                $this->assertSame(2, $sql->pdo->commits, 'Each existing component retains its own transaction.');
            } catch (\RuntimeException) {
                $this->assertNotSame('', $failure);
                $this->assertSame('older-ldr', $sql->pdo->markers['ldr']['runId'], 'A partial component success cannot update the feed completion marker.');
                $this->assertSame($failure === 'marker' ? 80.50 : 20.0, $sql->pdo->bonusRows['Retention Earner']);
            }
        }
    }

    public function test_uncorrelated_legacy_command_writes_bonus_without_any_feed_validation_or_queries(): void
    {
        Log::swap(new NullLogger);
        $previous = getenv('PAYROLL_COMMISSION_RUN_ID');
        putenv('PAYROLL_COMMISSION_RUN_ID');
        try {
            $sql = new LookbackFeedConnector;
            $beforeFeed = [$sql->pdo->rows, $sql->pdo->markers];
            $command = new GenerateRetentionBonusCommission;
            $result = (new \ReflectionMethod($command, 'persistBonusResults'))->invoke($command,
                $sql, 'ldr', '2098-09-01', [['agent' => 'Retention Earner', 'amount' => 80.50]], false,
                [['ID' => 'invalid-for-feed', 'AGENT' => null, 'AGENT_DEDUCTION' => 125.25]]);
            $this->assertSame(['attempted' => 1, 'written' => 1, 'failed' => 0], $result);
            $this->assertSame(80.50, $sql->pdo->bonusRows['Retention Earner']);
            $this->assertSame($beforeFeed, [$sql->pdo->rows, $sql->pdo->markers]);
            $this->assertSame(1, $sql->pdo->commits);
            foreach ($sql->queries as $query) {
                $this->assertStringNotContainsString('TblPayrollRetentionLookback', $query);
                $this->assertStringNotContainsString('TblEnrollment', $query);
                $this->assertStringNotContainsString('TblEmployees', $query);
            }
        } finally { $previous === false ? putenv('PAYROLL_COMMISSION_RUN_ID') : putenv('PAYROLL_COMMISSION_RUN_ID=' . $previous); }
    }

    public function test_correlated_sales_command_publishes_feed_with_customer_service_capture_disabled(): void
    {
        Log::swap(new NullLogger);
        $previousRun = getenv('PAYROLL_COMMISSION_RUN_ID');
        $previousCapture = getenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT');
        putenv('PAYROLL_COMMISSION_RUN_ID=' . self::RUN_ID);
        putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=0');
        try {
            $sql = new LookbackFeedConnector;
            $command = new GenerateRetentionBonusCommission;
            (new \ReflectionMethod($command, 'persistBonusResults'))->invoke($command,
                $sql, 'ldr', '2098-09-01', [['agent' => 'Retention Earner', 'amount' => 80.50]], false,
                [['ID' => '101', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => 125.25]]);
            $this->assertSame(self::RUN_ID, $sql->pdo->markers['ldr']['runId']);
            $this->assertSame(2, $sql->pdo->commits);
            $this->assertSame(125.25, $sql->pdo->rows['ldr'][0][6]);
        } finally {
            $previousRun === false ? putenv('PAYROLL_COMMISSION_RUN_ID') : putenv('PAYROLL_COMMISSION_RUN_ID=' . $previousRun);
            $previousCapture === false ? putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT') : putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=' . $previousCapture);
        }
    }

    public function test_ambiguous_or_unassigned_positive_deductions_fail_before_any_write(): void
    {
        foreach (['missing-agent', 'duplicate-cid', 'duplicate-enrollment', 'changed-enrollment', 'duplicate-employee', 'missing-employee', 'invalid-amount'] as $problem) {
            $sql = new LookbackFeedConnector;
            $rows = [['ID' => '101', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => 125.25]];
            if ($problem === 'missing-agent') $rows[0]['AGENT'] = null;
            if ($problem === 'duplicate-cid') $rows[] = $rows[0];
            if ($problem === 'duplicate-enrollment') $sql->enrollments[] = $sql->enrollments[0];
            if ($problem === 'changed-enrollment') $sql->enrollments[0]['Agent'] = 'Different Sales Agent';
            if ($problem === 'duplicate-employee') $sql->employees[] = ['PK' => 702, 'Employee_Name' => 'ALICE SALES'];
            if ($problem === 'missing-employee') $sql->employees = [];
            if ($problem === 'invalid-amount') $rows[0]['AGENT_DEDUCTION'] = -1;
            try {
                PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, $rows);
                $this->fail("{$problem} must not become a payable deduction.");
            } catch (\UnexpectedValueException) {
                $this->assertSame(0, $sql->writes, $problem);
            }
        }
    }

    public function test_manifest_order_is_deterministic_and_tampering_cannot_be_published(): void
    {
        $sql = new LookbackFeedConnector;
        $rows = [['ID' => '102', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => ''], ['ID' => '101', 'AGENT' => 'Alice Sales', 'AGENT_DEDUCTION' => 125.25]];
        $first = PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, $rows);
        $second = PayrollRetentionLookbackWriter::prepare($sql, 'ldr', '2098-09-01', self::RUN_ID, array_reverse($rows));
        $this->assertSame($first['payloadSha256'], $second['payloadSha256']);
        $first['rows'][0]['Agent_Deduction'] = 999;
        $this->expectException(\InvalidArgumentException::class);
        PayrollRetentionLookbackWriter::publish($sql, $first);
    }

    public function test_identity_scope_and_environment_run_id_are_validated(): void
    {
        $previous = getenv('PAYROLL_COMMISSION_RUN_ID');
        try {
            putenv('PAYROLL_COMMISSION_RUN_ID=' . self::RUN_ID);
            $this->assertSame(self::RUN_ID, PayrollRetentionLookbackWriter::runIdFromEnvironment());
            putenv('PAYROLL_COMMISSION_RUN_ID');
            $this->assertNull(PayrollRetentionLookbackWriter::runIdFromEnvironment());
            putenv('PAYROLL_COMMISSION_RUN_ID=invalid');
            $this->expectException(\InvalidArgumentException::class);
            PayrollRetentionLookbackWriter::runIdFromEnvironment();
        } finally {
            $previous === false ? putenv('PAYROLL_COMMISSION_RUN_ID') : putenv('PAYROLL_COMMISSION_RUN_ID=' . $previous);
        }
    }

    public function test_supplied_blank_or_invalid_run_identity_cannot_fall_back_to_legacy_writes(): void
    {
        $previous = getenv('PAYROLL_COMMISSION_RUN_ID');
        try {
            foreach (['', 'invalid', str_repeat('A', 32), self::RUN_ID . "\n"] as $invalid) {
                putenv('PAYROLL_COMMISSION_RUN_ID=' . $invalid);
                $sql = new LookbackFeedConnector;
                $command = new GenerateRetentionBonusCommission;
                try {
                    (new \ReflectionMethod($command, 'persistBonusResults'))->invoke($command,
                        $sql, 'ldr', '2098-09-01', [['agent' => 'Retention Earner', 'amount' => 80.50]], false, []);
                    $this->fail('A supplied invalid identity must not silently enter the legacy path.');
                } catch (\InvalidArgumentException) { $this->assertSame([], $sql->queries); }
            }
        } finally { $previous === false ? putenv('PAYROLL_COMMISSION_RUN_ID') : putenv('PAYROLL_COMMISSION_RUN_ID=' . $previous); }
    }
}

// These adapters cannot construct PDO connections or use environment credentials.
final class LookbackFeedPDO extends PDO
{
    public array $rows = ['ldr' => [['old' => 20]], 'plaw' => [['sibling' => 30]]];
    public array $markers = ['ldr' => ['runId' => 'older-ldr'], 'plaw' => ['runId' => 'older-plaw']];
    public int $commits = 0;
    public int $rollbacks = 0;
    public array $bonusRows = ['Retention Earner' => 20.0];
    public bool $failCommit = false;
    private ?array $before = null;
    public function __construct() {}
    public function beginTransaction(): bool { $this->before = [$this->rows, $this->markers, $this->bonusRows]; return true; }
    public function inTransaction(): bool { return $this->before !== null; }
    public function commit(): bool { if ($this->failCommit) return false; $this->commits++; $this->before = null; return true; }
    public function rollBack(): bool { $this->rollbacks++; [$this->rows, $this->markers, $this->bonusRows] = $this->before; $this->before = null; return true; }
}

final class LookbackFeedConnector extends DBConnector
{
    public LookbackFeedPDO $pdo;
    public array $enrollments = [['LLG_ID' => 'LLG-101', 'Agent' => 'Alice Sales']];
    public array $employees = [['PK' => 701, 'Employee_Name' => 'Alice Sales']];
    public string $failure = '';
    public int $writes = 0;
    public array $queries = [];
    public function __construct() { $this->pdo = new LookbackFeedPDO; }
    public function getSqlServerConnection(): PDO { return $this->pdo; }
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SELECT LLG_ID')) return ['success' => true, 'data' => $this->enrollments];
        if (str_starts_with($sql, 'SELECT PK')) return ['success' => true, 'data' => $this->employees];
        if (str_starts_with($sql, 'SELECT Source')) return ['success' => true, 'data' => []];
        $this->writes++;
        if (str_starts_with($sql, 'IF OBJECT_ID') || str_starts_with($sql, 'IF NOT EXISTS')) return ['success' => true];
        if (str_starts_with($sql, 'UPDATE dbo.TblCommissionReviewResults')) {
            foreach ($this->pdo->bonusRows as &$value) $value = 0.0;
            return ['success' => true];
        }
        if (str_starts_with($sql, 'MERGE dbo.TblCommissionReviewResults')) {
            if ($this->failure === 'bonus') return ['success' => false];
            $this->pdo->bonusRows[$params[3]] = $params[4];
            return ['success' => true];
        }
        if (str_starts_with($sql, 'DELETE')) { $this->pdo->rows[$params[0]] = []; return ['success' => true]; }
        if (str_starts_with($sql, 'INSERT INTO dbo.TblPayrollRetentionLookbackResults')) {
            if ($this->failure === 'row') return ['success' => false];
            $this->pdo->rows[$params[0]][] = $params;
            return ['success' => true];
        }
        if (str_starts_with($sql, 'MERGE dbo.TblPayrollRetentionLookbackRuns')) {
            if ($this->failure === 'marker') return ['success' => false];
            $this->pdo->markers[$params[0]] = ['runId' => $params[2], 'rowCount' => $params[3]];
            $this->pdo->failCommit = $this->failure === 'commit';
            return ['success' => true];
        }
        throw new \LogicException('Unexpected SQL in isolated Lookback feed test.');
    }
}
