<?php

namespace Cmd\Reports\Services;

use PDO;

/** Cross-host run boundary; independent of the payable replacement transaction. */
final class CommissionReportRunLock
{
    private bool $held = true;

    private function __construct(private PDO $connection, private string $resource) {}

    public static function acquire(DBConnector $sql, string $report, string $source, string $period): self
    {
        if (!preg_match('/^[a-z_]+$/D', $report) || !in_array($source, ['ldr', 'plaw'], true)
            || !preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $period)) {
            throw new \InvalidArgumentException('Invalid commission report lock identity.');
        }
        $resource = "commission-report:{$report}:{$source}:{$period}";
        $connection = $sql->getSqlServerConnection();
        $result = self::execute($connection,
            "SET NOCOUNT ON; DECLARE @result int; EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0; SELECT @result;",
            $resource
        );
        if (!in_array($result, [0, 1], true)) {
            throw new \RuntimeException("Commission report {$report}/{$source}/{$period} is already running or its lock could not be acquired (code {$result}). No report changes were made.");
        }
        return new self($connection, $resource);
    }

    public function release(): void
    {
        if (!$this->held) return;
        $result = self::execute($this->connection,
            "SET NOCOUNT ON; DECLARE @result int; EXEC @result = sys.sp_releaseapplock @Resource = ?, @LockOwner = 'Session'; SELECT @result;",
            $this->resource
        );
        if ($result !== 0) throw new \RuntimeException('Commission report run lock release failed; close this database session before retrying.');
        $this->held = false;
    }

    private static function execute(PDO $connection, string $sql, string $resource): int
    {
        // querySqlServer() treats DECLARE/EXEC as non-select and discards the return
        // code. Use this exact PDO session and inspect the procedure result instead.
        $statement = $connection->prepare($sql);
        if ($statement === false || !$statement->execute([$resource])) {
            throw new \RuntimeException('Could not execute commission report run lock operation.');
        }
        try {
            do {
                if ($statement->columnCount() > 0) {
                    $result = $statement->fetchColumn();
                    if (is_int($result) || (is_string($result) && preg_match('/^-?\d+$/D', $result))) {
                        return (int) $result;
                    }
                    throw new \RuntimeException('Commission report run lock returned an invalid result.');
                }
            } while ($statement->nextRowset());
            throw new \RuntimeException('Commission report run lock returned no result.');
        } finally {
            $statement->closeCursor();
        }
    }
}
