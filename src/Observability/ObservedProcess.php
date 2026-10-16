<?php

namespace Cmd\Reports\Observability;

use Throwable;

class ObservedProcess extends \Symfony\Component\Process\Process
{
    private ?float $traceStart = null;
    private bool $traceRecorded = false;

    public function start(?callable $callback = null, array $env = []): void
    {
        $this->traceStart = microtime(true);
        $this->traceRecorded = false;
        try {
            parent::start($callback, array_merge($env, ['CMD_TRACE_RUN_ID' => Telemetry::runId()]));
        } catch (Throwable $e) {
            $this->recordResult(false, $e);
            throw $e;
        }
    }

    public function wait(?callable $callback = null): int
    {
        try {
            $code = parent::wait($callback);
            $this->recordResult($code === 0, null, $code);
            return $code;
        } catch (Throwable $e) {
            $this->recordResult(false, $e);
            throw $e;
        }
    }

    private function recordResult(bool $success, ?Throwable $error = null, ?int $code = null): void
    {
        if (!$this->traceRecorded && $this->traceStart !== null) {
            $this->traceRecorded = true;
            Telemetry::record('process', 'execute', 'subprocess', $this->traceStart, $success, $error, $code);
        }
    }
}
