<?php

namespace Cmd\Reports\Observability;

class ObservedStatement extends \PDOStatement
{
    protected function __construct(private string $service) {}

    public function execute(?array $params = null): bool
    {
        return Telemetry::measure('database', 'execute', $this->service, fn () => parent::execute($params));
    }
}
