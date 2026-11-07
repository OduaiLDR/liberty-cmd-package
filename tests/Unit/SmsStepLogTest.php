<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsRunProgress;
use Cmd\Reports\Services\SmsStepLog;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsStepLogTest extends TestCase
{
    private const ID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    private Container $previous;

    /** @var array<int, array> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['cache' => ['default' => 'array',
            'stores' => ['file' => ['driver' => 'array'], 'array' => ['driver' => 'array']], 'prefix' => 'sms-test']]));
        $app->instance('cache', new CacheManager($app));
        $lines = &$this->lines;
        $app->instance('log', new class($lines)
        {
            public function __construct(private array &$lines) {}

            public function info(string $message, array $context = []): void
            {
                if ($message === 'SMS step') $this->lines[] = $context;
            }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        SmsStepLog::finish(self::ID);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        SmsStepLog::finish(self::ID);
        Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    public function test_each_step_reports_how_long_the_one_before_it_took(): void
    {
        SmsStepLog::start(self::ID, 'picking');
        usleep(120000);
        SmsStepLog::mark(self::ID, 'counting');
        SmsStepLog::finish(self::ID);
        $this->assertSame(['picking', 'counting', 'finished'], array_column($this->lines, 'step'));
        $this->assertNull($this->lines[0]['previous_seconds']);
        $this->assertSame('picking', $this->lines[1]['previous']);
        $this->assertGreaterThanOrEqual(0.1, $this->lines[1]['previous_seconds']);
    }

    public function test_repeating_a_step_logs_once_so_progress_ticks_dont_flood_the_log(): void
    {
        SmsStepLog::start(self::ID, 'writing');
        SmsStepLog::mark(self::ID, 'writing');
        SmsStepLog::mark(self::ID, 'writing');
        $this->assertCount(1, $this->lines);
    }

    public function test_a_retry_starts_its_own_clock(): void
    {
        SmsStepLog::start(self::ID, 'picking');
        SmsStepLog::start(self::ID, 'picking');
        $this->assertCount(2, $this->lines);
        $this->assertNull($this->lines[1]['previous_seconds'], 'the gap since a failed attempt is not a step time');
    }

    public function test_two_runs_keep_separate_clocks(): void
    {
        $other = '11111111-2222-4333-8444-555555555555';
        SmsStepLog::start(self::ID, 'picking');
        SmsStepLog::start($other, 'writing');
        SmsStepLog::mark(self::ID, 'counting');
        SmsStepLog::finish($other);
        $this->assertSame('picking', $this->lines[2]['previous']);
    }

    public function test_a_broken_log_never_stops_a_run(): void
    {
        Container::getInstance()->instance('log', new class
        {
            public function info(string $message, array $context = []): void
            {
                throw new RuntimeException('log disk full');
            }
        });
        Facade::clearResolvedInstances();
        SmsStepLog::start(self::ID, 'picking');
        SmsStepLog::mark(self::ID, 'counting');
        SmsStepLog::finish(self::ID);
        $this->addToAssertionCount(1);
    }

    public function test_a_phase_change_in_progress_logs_a_step_and_a_tick_doesnt(): void
    {
        $progress = new SmsRunProgress;
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 1, 'total' => 10, 'scanned' => 1]);
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 2, 'total' => 10, 'scanned' => 2]);
        $progress->write(self::ID, ['phase' => 'finalizing_selection', 'done' => 10, 'total' => 10, 'scanned' => 10]);
        $this->assertSame(['selecting', 'finalizing_selection'], array_column($this->lines, 'step'));
    }
}
