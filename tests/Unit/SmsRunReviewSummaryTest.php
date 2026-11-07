<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsRunReviewSummary;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsRunReviewSummaryTest extends TestCase
{
    private const ID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['cache' => ['default' => 'array',
            'stores' => ['file' => ['driver' => 'array'], 'array' => ['driver' => 'array']], 'prefix' => 'sms-test']]));
        $app->instance('cache', new CacheManager($app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Carbon::setTestNow(Carbon::parse('2026-11-07T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    private function summary(iterable|\Throwable $rows): SmsRunReviewSummary
    {
        return new class($rows) extends SmsRunReviewSummary
        {
            public function __construct(private iterable|\Throwable $rows) {}

            protected function tierRows(string $requestId): iterable
            {
                if ($this->rows instanceof \Throwable) throw $this->rows;

                return $this->rows;
            }
        };
    }

    public function test_the_worker_saves_tiers_in_order_with_the_lowest_debt(): void
    {
        $summary = $this->summary([(object) ['tier' => 'T9', 'records' => '200', 'lowest' => '85000'], (object) ['tier' => 'T4', 'records' => 100, 'lowest' => '26000.00']]);
        $summary->record(self::ID);
        $read = $summary->read(strtolower(self::ID));
        $this->assertSame(['T4', 'T9'], array_column($read['tiers'], 'tier'));
        $this->assertSame([100, 200], array_column($read['tiers'], 'records'));
        $this->assertSame(26000.0, $read['lowest_debt']);
        $this->assertSame('2026-11-07T10:00:00+00:00', $read['computed_at']);
    }

    public function test_a_failed_query_never_stops_the_selection_and_leaves_nothing_saved(): void
    {
        $summary = $this->summary(new RuntimeException('database went away'));
        $summary->record(self::ID);
        $this->assertNull($summary->read(self::ID));
    }

    public function test_malformed_rows_save_nothing_rather_than_a_partial_split(): void
    {
        foreach ([[['tier' => 'T10', 'records' => 1, 'lowest' => 1]], [['tier' => 'T1', 'records' => 0, 'lowest' => 1]],
            [['tier' => 'T1', 'records' => 1, 'lowest' => 'x']], []] as $rows) {
            $summary = $this->summary($rows);
            $summary->record(self::ID);
            $this->assertNull($summary->read(self::ID));
        }
    }

    public function test_unknown_debt_stays_unknown_and_no_tier_sorts_last(): void
    {
        $summary = $this->summary([['tier' => null, 'records' => 3, 'lowest' => null], ['tier' => 'T2', 'records' => 5, 'lowest' => null]]);
        $summary->record(self::ID);
        $read = $summary->read(self::ID);
        $this->assertSame(['T2', null], array_column($read['tiers'], 'tier'));
        $this->assertNull($read['lowest_debt']);
    }

    public function test_one_run_cant_read_another_runs_summary(): void
    {
        $summary = $this->summary([['tier' => 'T1', 'records' => 1, 'lowest' => 1000]]);
        $summary->record(self::ID);
        $this->assertNull($summary->read('11111111-2222-4333-8444-555555555555'));
        $summary->forget(self::ID);
        $this->assertNull($summary->read(self::ID));
    }
}
