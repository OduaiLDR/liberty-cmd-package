<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\CommissionReportRunLock;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use PDO;
use PDOStatement;
use ReflectionMethod;

final class NsfRunGuardTest extends TestCase
{
    public function test_same_source_month_competing_session_cannot_enter_until_release(): void
    {
        $registry = new \stdClass;
        $registry->locks = [];
        $one = new NsfLockConnector(new NsfLockPdo($registry));
        $two = new NsfLockConnector(new NsfLockPdo($registry));
        $lock = CommissionReportRunLock::acquire($one, 'nsf', 'ldr', '2098-01-01');
        try {
            CommissionReportRunLock::acquire($two, 'nsf', 'ldr', '2098-01-01');
            $this->fail('Overlapping writer must not enter.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('already running', $error->getMessage());
        }
        // Different source or month is not unnecessarily serialized.
        $otherSource = CommissionReportRunLock::acquire($two, 'nsf', 'plaw', '2098-01-01');
        $otherMonth = CommissionReportRunLock::acquire($two, 'nsf', 'ldr', '2098-02-01');
        $one->pdo->commit(); // Simulate the atomic writer; Session lock must survive.
        $this->assertCount(3, $registry->locks);
        $lock->release();
        $replacement = CommissionReportRunLock::acquire($two, 'nsf', 'ldr', '2098-01-01');
        $replacement->release();
        $otherSource->release();
        $otherMonth->release();
        $this->assertSame([], $registry->locks);
        $this->assertStringContainsString("@LockOwner = 'Session'", $one->pdo->queries[0]);
        $this->assertStringContainsString('@LockTimeout = 0', $one->pdo->queries[0]);
    }

    public function test_failed_work_releases_session_lock_in_finally(): void
    {
        $registry = (object) ['locks' => []];
        $connector = new NsfLockConnector(new NsfLockPdo($registry));
        try {
            $lock = CommissionReportRunLock::acquire($connector, 'nsf', 'ldr', '2098-01-01');
            try { throw new \RuntimeException('synthetic source failure'); }
            finally { $lock->release(); }
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic source failure', $error->getMessage());
        }
        $this->assertSame([], $registry->locks);
    }

    public function test_lock_procedure_invalid_or_negative_result_fails_closed(): void
    {
        foreach ([null, -1, -2, -3, -999] as $result) {
            $connector = new NsfLockConnector(new NsfLockPdo((object) ['locks' => []], true, $result));
            try {
                CommissionReportRunLock::acquire($connector, 'nsf', 'ldr', '2098-01-01');
                $this->fail('An unconfirmed lock must never permit work.');
            } catch (\RuntimeException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_real_snowflake_envelope_and_genuine_empty_are_accepted_but_failure_is_not(): void
    {
        $command = new GenerateNSFCommissionReport;
        $fetch = new ReflectionMethod($command, 'fetchNSFRows');
        $config = ['custom_agent' => 1, 'custom_nsf_return' => 2, 'custom_nsf_action' => 3, 'custom_nsf_recoup' => 4];
        foreach ([['data' => [], 'rowCount' => 0, 'columns' => []], ['success' => true, 'data' => []]] as $envelope) {
            $this->assertSame([], $fetch->invoke($command, new NsfEnvelopeConnector($envelope), $config, '2098-01-01', '2098-01-31'));
        }
        foreach ([['success' => false, 'data' => []], [], ['data' => null], ['data' => 'bad'], ['data' => ['bad-row']]] as $envelope) {
            try {
                $fetch->invoke($command, new NsfEnvelopeConnector($envelope), $config, '2098-01-01', '2098-01-31');
                $this->fail('Invalid source data cannot be published as a zero result.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('NSF source query', $error->getMessage());
            }
        }
    }

    public function test_agent_and_manager_share_earned_year_and_cutoff_boundaries(): void
    {
        $agent = new GenerateNSFCommissionReport;
        $manager = new GenerateRetentionManagerCommission;
        $agentValid = new ReflectionMethod($agent, 'isValidCommission');
        $managerValid = new ReflectionMethod($manager, 'isValidCommission');
        foreach ([
            ['2098-01-01', '2097-01-02', '2098-01-03', false],
            ['2098-01-01', '2098-01-02', '2098-02-05', true],
            ['2098-01-01', '2098-01-02', '2098-02-06', false],
            ['2098-01-01', '2098-01-02', '2098-01-02', false],
            ['2098-12-01', '2098-12-02', '2099-01-05', true],
        ] as [$returned, $recoup, $cleared, $expected]) {
            $row = ['NSF_RETURNED_DATE' => $returned, 'NSF_RECOUP_DATE' => $recoup, 'CLEARED_DATE' => $cleared];
            $this->assertSame($expected, $agentValid->invoke($agent, $row));
            $this->assertSame($expected, $managerValid->invoke($manager, $returned, $recoup, $cleared));
        }
        $this->assertFalse($agentValid->invoke($agent, ['NSF_RETURNED_DATE' => 'invalid', 'NSF_RECOUP_DATE' => '2098-01-02', 'CLEARED_DATE' => '2098-01-03']));
    }
}

final class NsfLockConnector extends DBConnector
{
    public function __construct(public NsfLockPdo $pdo) {}
    public function getSqlServerConnection(): PDO { return $this->pdo; }
}

final class NsfLockPdo extends PDO
{
    public array $queries = [];
    public function __construct(public \stdClass $registry, private bool $forced = false, private mixed $result = null) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new NsfLockStatement($this, $query, $this->forced, $this->result);
    }
    public function commit(): bool { return true; }
}

final class NsfLockStatement extends PDOStatement
{
    public function __construct(private NsfLockPdo $pdo, private string $query, private bool $forced, private mixed $result) {}
    public function execute(?array $params = null): bool
    {
        if ($this->forced) return true;
        $key = $params[0];
        if (str_contains($this->query, 'sp_releaseapplock')) {
            $this->result = ($this->pdo->registry->locks[$key] ?? null) === $this->pdo ? 0 : -999;
            if ($this->result === 0) unset($this->pdo->registry->locks[$key]);
        } else {
            $this->result = isset($this->pdo->registry->locks[$key]) ? -1 : 0;
            if ($this->result === 0) $this->pdo->registry->locks[$key] = $this->pdo;
        }
        return true;
    }
    public function columnCount(): int { return 1; }
    public function fetchColumn(int $column = 0): mixed { return $this->result; }
    public function closeCursor(): bool { return true; }
}

final class NsfEnvelopeConnector extends DBConnector
{
    public function __construct(private array $result) {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array { return $this->result; }
}
