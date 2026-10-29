<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregate timing for windowed tier selection: candidate loading versus source resolution,
 * per priority phase. Counts and durations only; no consumer value is ever recorded. Logging
 * sits outside the selection transaction and can never fail or alter a selection.
 */
final class SmsTierSelectionMetrics
{
    public const PROGRESS_EVERY = 50;
    private const PHASES = ['never_texted_dated', 'never_texted_undated', 'previously_texted'];

    private int $started;
    private int $windows = 0;
    private array $phases = [];

    public function __construct(private string $requestId, private int $target, private int $windowSize)
    {
        $this->started = hrtime(true);
        foreach (self::PHASES as $name) $this->phases[$name] = ['windows' => 0, 'candidates' => 0, 'accepted' => 0, 'load_ns' => 0, 'resolve_ns' => 0];
    }

    public function window(int $phase, int $candidates, int $accepted, int $loadNs, int $resolveNs): void
    {
        try {
            $name = self::PHASES[$phase] ?? 'unknown';
            $this->phases[$name] ??= ['windows' => 0, 'candidates' => 0, 'accepted' => 0, 'load_ns' => 0, 'resolve_ns' => 0];
            $this->phases[$name]['windows']++;
            $this->phases[$name]['candidates'] += max(0, $candidates);
            $this->phases[$name]['accepted'] += max(0, $accepted);
            $this->phases[$name]['load_ns'] += max(0, $loadNs);
            $this->phases[$name]['resolve_ns'] += max(0, $resolveNs);
            if (++$this->windows % self::PROGRESS_EVERY === 0) $this->log('SMS tier selection progress', $this->snapshot());
        } catch (Throwable) {
            // Metrics are advisory; they never interrupt selection.
        }
    }

    public function finish(int $selected): ?array
    {
        try {
            $summary = $this->snapshot() + ['selected' => $selected, 'shortfall' => max(0, $this->target - $selected)];
            $this->log('SMS tier selection completed', $summary);
            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    public function snapshot(): array
    {
        $totals = ['windows' => 0, 'candidates' => 0, 'accepted' => 0, 'load_ns' => 0, 'resolve_ns' => 0];
        $phases = [];
        foreach ($this->phases as $name => $phase) {
            foreach ($totals as $key => $value) $totals[$key] = $value + $phase[$key];
            if ($phase['windows'] > 0) $phases[$name] = $this->shape($phase);
        }
        return ['request_id' => $this->requestId, 'target' => $this->target, 'window_size' => $this->windowSize,
            'elapsed_seconds' => round((hrtime(true) - $this->started) / 1e9, 3)] + $this->shape($totals) + ['phases' => $phases];
    }

    private function shape(array $counts): array
    {
        return ['windows' => $counts['windows'], 'candidates' => $counts['candidates'], 'accepted' => $counts['accepted'],
            'acceptance_rate' => $counts['candidates'] > 0 ? round($counts['accepted'] / $counts['candidates'], 4) : null,
            'candidate_load_seconds' => round($counts['load_ns'] / 1e9, 3), 'source_resolve_seconds' => round($counts['resolve_ns'] / 1e9, 3)];
    }

    private function log(string $message, array $context): void
    {
        try { Log::info($message, $context); } catch (Throwable) {
            // Metrics are advisory; a logging failure never interrupts selection.
        }
    }
}
