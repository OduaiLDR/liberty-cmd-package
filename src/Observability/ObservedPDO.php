<?php

namespace Cmd\Reports\Observability;

use PDO;
use PDOStatement;

class ObservedPDO extends PDO
{
    private string $service;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $this->service = explode(':', $dsn, 2)[0];
        Telemetry::measure('database', 'connect', $this->service, fn () => parent::__construct($dsn, $username, $password, $options));
        if (Telemetry::enabled()) {
            try {
                if (!$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ObservedStatement::class, [$this->service]])) {
                    Telemetry::record('database', 'statement-instrumentation-unavailable', $this->service, microtime(true), false);
                }
            } catch (\Throwable $e) {
                Telemetry::record('database', 'statement-instrumentation-unavailable', $this->service, microtime(true), false, $e);
            }
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return Telemetry::measure('database', 'query', $this->service,
            fn () => $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs));
    }

    public function exec(string $statement): int|false
    {
        return Telemetry::measure('database', 'exec', $this->service, fn () => parent::exec($statement));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return Telemetry::measure('database', 'prepare', $this->service, fn () => parent::prepare($query, $options));
    }

    public function beginTransaction(): bool { return Telemetry::measure('database', 'begin', $this->service, fn () => parent::beginTransaction()); }
    public function commit(): bool { return Telemetry::measure('database', 'commit', $this->service, fn () => parent::commit()); }
    public function rollBack(): bool { return Telemetry::measure('database', 'rollback', $this->service, fn () => parent::rollBack()); }
}
