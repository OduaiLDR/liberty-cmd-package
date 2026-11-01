<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsTierSelectionMetrics;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class SmsTierSelectionMetricsTest extends TestCase
{
    private ?Container $previous = null;
    private object $logger;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        $this->logger = new class {
            public array $entries = [];
            public function info(string $message, array $context = []): void { $this->entries[] = [$message, $context]; }
        };
        $container->instance('log', $this->logger);
        Container::setInstance($container);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previous); Container::setInstance($this->previous);
    }

    public function test_aggregates_candidates_acceptance_and_stage_time_per_priority_phase(): void
    {
        $metrics = new SmsTierSelectionMetrics('req-1', 9000, 5000);
        $metrics->window(0, 5000, 4000, 2_000_000_000, 6_000_000_000);
        $metrics->window(0, 5000, 1000, 1_000_000_000, 3_000_000_000);
        $metrics->window(2, 200, 50, 500_000_000, 250_000_000);
        $summary = $metrics->finish(5050);

        self::assertSame(3, $summary['windows']); self::assertSame(10200, $summary['candidates']); self::assertSame(5050, $summary['accepted']);
        self::assertSame(0.4951, $summary['acceptance_rate']); self::assertSame(3.5, $summary['candidate_load_seconds']);
        self::assertSame(9.25, $summary['source_resolve_seconds']); self::assertSame(3950, $summary['shortfall']);
        self::assertSame(['never_texted_dated', 'previously_texted'], array_keys($summary['phases']));
        self::assertSame(0.5, $summary['phases']['never_texted_dated']['acceptance_rate']);
        self::assertSame([['SMS tier selection completed', $summary]], $this->logger->entries);
    }

    public function test_progress_is_logged_every_fifty_windows_without_consumer_values(): void
    {
        $metrics = new SmsTierSelectionMetrics('req-2', 1_000_000, 5000);
        for ($i = 0; $i < SmsTierSelectionMetrics::PROGRESS_EVERY * 2 + 3; $i++) $metrics->window(0, 5000, 10, 1, 1);
        self::assertCount(2, $this->logger->entries);
        self::assertSame('SMS tier selection progress', $this->logger->entries[1][0]);
        self::assertSame(100, $this->logger->entries[1][1]['windows']);
        $keys = array_keys($this->logger->entries[1][1]);
        self::assertSame(['request_id', 'target', 'window_size', 'elapsed_seconds', 'windows', 'candidates', 'accepted',
            'acceptance_rate', 'candidate_load_seconds', 'source_resolve_seconds', 'phases'], $keys);
    }

    public function test_a_logging_failure_never_interrupts_selection(): void
    {
        $failing = new class { public function info(): void { throw new \RuntimeException('log store unavailable'); } };
        Facade::getFacadeApplication()->instance('log', $failing); Facade::clearResolvedInstances();
        $metrics = new SmsTierSelectionMetrics('req-3', 10, 5);
        for ($i = 0; $i < SmsTierSelectionMetrics::PROGRESS_EVERY; $i++) $metrics->window(1, 5, 1, 1, 1);
        self::assertSame(10, $metrics->finish(10)['selected']);
    }
}
