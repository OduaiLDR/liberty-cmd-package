<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PDO;
use Psr\Log\NullLogger;

class CommissionResultsAtomicTest extends TestCase
{
    public function test_success_replaces_only_owned_component_and_clears_removed_earners(): void
    {
        Log::swap(new NullLogger);
        $sql = new AtomicResultsConnector;
        $result = CommissionResultsWriter::replaceComponent($sql, 'retention', 'ldr', '2098-01-01', 'Commission', [
            ['agent' => 'Alice', 'amount' => 120],
        ]);
        $this->assertSame(['attempted' => 1, 'written' => 1, 'failed' => 0], $result);
        $this->assertSame(['Commission' => 120.0, 'Bonus_Commission' => 25.0], $sql->pdo->rows['Alice']);
        $this->assertSame(['Commission' => 0.0, 'Bonus_Commission' => 15.0], $sql->pdo->rows['Bob']);
        $this->assertSame(1, $sql->pdo->commits);
        $this->assertFalse($sql->pdo->inTransaction());
    }

    public function test_failure_after_first_employee_rolls_back_reset_and_every_upsert(): void
    {
        Log::swap(new NullLogger);
        $sql = new AtomicResultsConnector;
        $before = $sql->pdo->rows;
        $sql->failMerge = 2;
        try {
            CommissionResultsWriter::replaceComponent($sql, 'retention', 'ldr', '2098-01-01', 'Bonus_Commission', [
                ['agent' => 'Alice', 'amount' => 900], ['agent' => 'Bob', 'amount' => 800],
            ]);
            $this->fail('Partial results must never succeed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('do not mark this report ready', $error->getMessage());
        }
        $this->assertSame($before, $sql->pdo->rows);
        $this->assertSame(1, $sql->pdo->rollbacks);
        $this->assertSame(0, $sql->pdo->commits);
    }

    public function test_failed_reset_and_schema_verification_never_publish_rows(): void
    {
        Log::swap(new NullLogger);
        foreach (['failReset', 'failSchema'] as $failure) {
            $sql = new AtomicResultsConnector;
            $before = $sql->pdo->rows;
            $sql->$failure = true;
            try {
                CommissionResultsWriter::replaceComponent($sql, 'nsf', 'ldr', '2098-01-01', 'Commission', []);
                $this->fail('A failed preparation must not report success, even with no employees.');
            } catch (\RuntimeException) {
                $this->assertSame($before, $sql->pdo->rows);
            }
            $this->assertSame(0, $sql->pdo->commits);
            $this->assertFalse($sql->pdo->inTransaction());
        }
    }

    public function test_valid_empty_result_clears_stale_amounts_without_touching_sibling(): void
    {
        Log::swap(new NullLogger);
        $sql = new AtomicResultsConnector;
        CommissionResultsWriter::replaceComponent($sql, 'nsf', 'ldr', '2098-01-01', 'Commission', []);
        $this->assertSame(0.0, $sql->pdo->rows['Alice']['Commission']);
        $this->assertSame(25.0, $sql->pdo->rows['Alice']['Bonus_Commission']);
        $this->assertSame(1, $sql->pdo->commits);
    }

    public function test_ambiguous_employee_inputs_fail_before_any_write(): void
    {
        $sql = new AtomicResultsConnector;
        $this->expectException(\InvalidArgumentException::class);
        CommissionResultsWriter::replaceComponent($sql, 'nsf', 'ldr', '2098-01-01', 'Commission', [
            ['agent' => 'Alice Smith', 'amount' => 50], ['agent' => ' ALICE  SMITH ', 'amount' => 70],
        ]);
    }
}

// Transaction test doubles deliberately cannot open a network connection.
final class AtomicResultsPDO extends PDO
{
    public array $rows = [
        'Alice' => ['Commission' => 100.0, 'Bonus_Commission' => 25.0],
        'Bob' => ['Commission' => 50.0, 'Bonus_Commission' => 15.0],
    ];
    public int $commits = 0;
    public int $rollbacks = 0;
    private ?array $before = null;
    public function __construct() {}
    public function beginTransaction(): bool { $this->before = $this->rows; return true; }
    public function inTransaction(): bool { return $this->before !== null; }
    public function commit(): bool { $this->commits++; $this->before = null; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->rows = $this->before; $this->before = null; return true; }
}

final class AtomicResultsConnector extends DBConnector
{
    public AtomicResultsPDO $pdo;
    public bool $failReset = false;
    public bool $failSchema = false;
    public int $failMerge = 0;
    private int $merges = 0;
    public function __construct() { $this->pdo = new AtomicResultsPDO; }
    public function getSqlServerConnection(): PDO { return $this->pdo; }
    public function querySqlServer(string $sql, array $params = []): array
    {
        if (str_starts_with($sql, 'IF NOT EXISTS')) return ['success' => !$this->failSchema];
        if (preg_match('/^UPDATE.*SET (Commission|Bonus_Commission) = 0/s', $sql, $match)) {
            if ($this->failReset) return ['success' => false];
            foreach ($this->pdo->rows as &$row) $row[$match[1]] = 0.0;
            return ['success' => true];
        }
        if (str_starts_with($sql, 'MERGE')) {
            if (++$this->merges === $this->failMerge) return ['success' => false];
            preg_match('/t\.(Commission|Bonus_Commission) = s.Amount/', $sql, $match);
            $this->pdo->rows[$params[3]][$match[1]] = $params[4];
            return ['success' => true];
        }
        throw new \LogicException('Unexpected SQL in atomic commission test.');
    }
}
