<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit reviewed repairs; discovery and choosing the correct identity are separate work. */
final class ContactRepair
{
    public const MAX_ROWS = 10;
    private const COLUMNS = [
        'TblContacts' => ['LLG_ID', 'External_ID', 'Client', 'Email', 'Phone', 'Agent'],
        'TblEnrollment' => ['LLG_ID', 'Client', 'Agent'],
    ];

    public function __construct(
        private PDO $pdo,
        private string $server,
        private string $database,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function validatePlan(array $plan): void
    {
        if (($plan['version'] ?? null) !== 1 || !is_string($plan['reviewed_by'] ?? null)
            || trim($plan['reviewed_by']) === '') {
            throw new InvalidArgumentException('A version 1 plan and named reviewer are required.');
        }
        $rows = $plan['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1
            || count($rows) > self::MAX_ROWS || ($plan['expected_rows'] ?? null) !== count($rows)) {
            throw new InvalidArgumentException('Require 1–10 explicit rows and matching expected_rows.');
        }
        $seen = $destinations = [];
        foreach ($rows as $row) {
            $table = $row['table'] ?? '';
            if (!is_string($table) || !isset(self::COLUMNS[$table])
                || !preg_match('/^[1-9][0-9]*$/D', (string) ($row['pk'] ?? ''))
                || !is_string($row['evidence'] ?? null) || strlen(trim($row['evidence'])) < 10) {
                throw new InvalidArgumentException('Each row needs an allowed table, positive PK, and reviewed source evidence.');
            }
            $key = $table . ':' . $row['pk'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate physical row in plan.');
            }
            $seen[$key] = true;
            $before = $row['before'] ?? null;
            $changes = $row['changes'] ?? null;
            if (!is_array($before) || count($before) !== count(self::COLUMNS[$table])
                || array_diff(self::COLUMNS[$table], array_keys($before))
                || !is_array($changes) || !$changes || array_diff(array_keys($changes), ['LLG_ID', 'Agent'])) {
                throw new InvalidArgumentException('Pin the complete identity before-image; only LLG_ID and Agent may change.');
            }
            foreach (array_merge(array_values($before), array_values($changes)) as $value) {
                if ($value !== null && !is_string($value)) {
                    throw new InvalidArgumentException('Before-images and proposed values must be exact strings or null.');
                }
            }
            if (trim((string) $before['Client']) === '' || trim((string) $before['LLG_ID']) === ''
                || ($table === 'TblContacts' && trim((string) $before['Email']) === '' && trim((string) $before['Phone']) === '')) {
                throw new InvalidArgumentException('Missing identity evidence; ambiguous records cannot be repaired.');
            }
            $after = array_replace($before, $changes);
            if (trim((string) $after['LLG_ID']) === '' || $after === $before) {
                throw new InvalidArgumentException('Require a nonempty destination ID and an actual proposed change.');
            }
            $destination = $table . ':' . $after['LLG_ID'];
            if (isset($destinations[$destination])) {
                throw new InvalidArgumentException('Multiple proposed rows occupy the same destination ID.');
            }
            $destinations[$destination] = true;
        }
    }

    /** No transaction, locks, DML, or connection-setting statements on this path. */
    public function preview(array $plan): array
    {
        self::validatePlan($plan);

        return $this->inspect($plan, false);
    }

    public static function assertLocalTarget(string $server, string $database): void
    {
        if (!preg_match('/^(127\.0\.0\.1|localhost)(,[0-9]{1,5})?$/D', $server)
            || $database !== 'contact_sync_test') {
            throw new RuntimeException('Apply is restricted to loopback / contact_sync_test. Remote and Azure writes are disabled.');
        }
    }

    /** Local rehearsal only. There is deliberately no production-apply override. */
    public function applyLocal(array $plan): array
    {
        self::validatePlan($plan);
        self::assertLocalTarget($this->server, $this->database);
        $target = $this->select("SELECT DB_NAME() AS db_name, CAST(SERVERPROPERTY('EngineEdition') AS int) AS engine")[0];
        if ($target['db_name'] !== 'contact_sync_test' || !in_array((int) $target['engine'], [2, 3, 4], true)) {
            throw new RuntimeException('The connected database is not the isolated local SQL Server fixture.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('An existing transaction is not allowed.');
        }
        // HOLDLOCK provides serializable row/range locks without changing session isolation.
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Could not begin the local repair transaction; no writes were attempted.');
        }
        try {
            $report = $this->inspect($plan, true);
            foreach ($report['rows'] as $row) {
                if (!in_array($row['status'], ['ready', 'already_applied'], true)) {
                    throw new RuntimeException("Repair blocked: {$row['table']} PK {$row['pk']}: {$row['status']}");
                }
            }
            $applied = 0;
            foreach ($plan['rows'] as $i => $row) {
                if ($report['rows'][$i]['status'] === 'already_applied') {
                    continue;
                }
                $bindings = array_values($row['changes']);
                $set = implode(', ', array_map(static fn (string $column): string => "[{$column}] = ?", array_keys($row['changes'])));
                $where = ['[PK] = ?'];
                $bindings[] = $row['pk'];
                foreach ($row['before'] as $column => $value) {
                    if ($value === null) {
                        $where[] = "[{$column}] IS NULL";
                    } else {
                        // VARBINARY comparisons are exact, including case and trailing spaces.
                        $where[] = "CONVERT(varbinary(max), CONVERT(nvarchar(max), [{$column}])) = CONVERT(varbinary(max), CONVERT(nvarchar(max), ?))";
                        $bindings[] = $value;
                    }
                }
                $statement = $this->pdo->prepare("UPDATE [dbo].[{$row['table']}] SET {$set} WHERE " . implode(' AND ', $where));
                $statement->execute($bindings);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Expected exactly one affected row; rolling back the complete plan.');
                }
                $applied++;
            }
            $verified = $this->inspect($plan, true);
            foreach ($verified['rows'] as $row) {
                if ($row['status'] !== 'already_applied') {
                    throw new RuntimeException('Post-write verification failed; rolling back the complete plan.');
                }
            }
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Local repair commit failed. Verify the saved plan with a fresh preview.');
            }

            return ['applied_rows' => $applied, 'before' => $report, 'after' => $verified];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function inspect(array $plan, bool $lock): array
    {
        $report = ['expected_rows' => $plan['expected_rows'], 'rows' => []];
        $hint = $lock ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
        foreach ($plan['rows'] as $row) {
            $columns = implode(', ', array_map(static fn (string $column): string => "[{$column}]", self::COLUMNS[$row['table']]));
            $found = $this->select("SELECT TOP (2) {$columns} FROM [dbo].[{$row['table']}]{$hint} WHERE [PK] = ?", [$row['pk']]);
            $after = array_replace($row['before'], $row['changes']);
            $status = count($found) === 0 ? 'missing' : (count($found) > 1 ? 'ambiguous_pk' : 'stale');
            if (count($found) === 1) {
                $actual = array_map(static fn ($value) => $value === null ? null : (string) $value, $found[0]);
                if ($actual === $this->ordered($row['table'], $after)) {
                    $status = 'already_applied';
                } elseif ($actual === $this->ordered($row['table'], $row['before'])) {
                    $status = 'ready';
                }
                $occupied = $this->select("SELECT TOP (1) [PK] FROM [dbo].[{$row['table']}]{$hint} WHERE [LLG_ID] = ? AND [PK] <> ?", [$after['LLG_ID'], $row['pk']]);
                if ($occupied) {
                    $status = 'occupied_destination';
                }
            }
            $report['rows'][] = [
                'table' => $row['table'], 'pk' => (string) $row['pk'], 'status' => $status,
                'observed' => $found, 'expected_before' => $row['before'], 'proposed_after' => $after,
                'evidence' => $row['evidence'],
            ];
        }

        return $report;
    }

    private function ordered(string $table, array $row): array
    {
        return array_replace(array_fill_keys(self::COLUMNS[$table], null), $row);
    }

    private function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
