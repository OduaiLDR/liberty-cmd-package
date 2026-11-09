<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A run step may last RUN_STEP_TIMEOUT, so it must never be queued where the queue would hand it to a second worker sooner. */
final class SmsRunStepQueueGuardTest extends TestCase
{
    private Container $previous;

    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['sms-exports' => ['queue' => 'tu-export']]));
        $test = $this;
        $app->instance(Dispatcher::class, new class($test)
        {
            public function __construct(private SmsRunStepQueueGuardTest $test) {}

            public function dispatch($job) { $this->test->record($job); return null; }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    public function record(object $job): void
    {
        $this->dispatched[] = $job;
    }

    private function queue(string $connection, int $retryAfter): SmsExportQueue
    {
        config(['queue.default' => $connection, 'queue.connections.'.$connection.'.retry_after' => $retryAfter]);

        return new SmsExportQueue;
    }

    private function refused(callable $call, string $text): void
    {
        try {
            $call();
            self::fail('Expected a 503.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->getStatusCode());
            self::assertStringContainsString($text, $error->getMessage());
        }
    }

    public function test_the_live_setting_of_14400_seconds_accepts_run_steps_and_other_jobs(): void
    {
        $queue = $this->queue('database', 14400);
        $queue->assertReady();
        $queue->assertReadyForRunSteps();
        self::assertGreaterThan(SmsExportQueue::RUN_STEP_TIMEOUT, 14400);
    }

    public static function tooShortForRunSteps(): array
    {
        return ['one above the job limit' => [7001], 'two hours' => [7200], 'equal to the step limit' => [SmsExportQueue::RUN_STEP_TIMEOUT]];
    }

    #[DataProvider('tooShortForRunSteps')]
    public function test_a_queue_that_suits_short_jobs_still_refuses_run_steps(int $retryAfter): void
    {
        $queue = $this->queue('database', $retryAfter);
        $queue->assertReady();
        $this->refused(fn () => $queue->assertReadyForRunSteps(), 'above 14000 seconds');
    }

    public static function notAsynchronous(): array
    {
        return ['sync' => ['sync', 15000], 'redis' => ['redis', 15000], 'database too short' => ['database', 7000]];
    }

    #[DataProvider('notAsynchronous')]
    public function test_run_steps_keep_every_existing_queue_refusal(string $connection, int $retryAfter): void
    {
        $queue = $this->queue($connection, $retryAfter);
        $this->refused(fn () => $queue->assertReadyForRunSteps(), 'asynchronous database queue');
    }

    public function test_a_run_step_is_not_queued_and_the_run_is_failed_when_the_queue_is_too_short(): void
    {
        $this->queue('database', 7200);
        [$service, $failed] = $this->service();
        $this->refused(fn () => $this->dispatch($service, 'prepare'), 'above 14000 seconds');
        self::assertSame([], $this->dispatched);
        self::assertCount(1, $failed->calls);
        self::assertSame(['req-1', 'prepare'], array_slice($failed->calls[0], 0, 2));
        self::assertStringContainsString('The step could not be queued', $failed->calls[0][2]);
    }

    public function test_a_run_step_is_queued_once_on_the_live_setting(): void
    {
        $this->queue('database', 14400);
        [$service, $failed] = $this->service();
        $this->dispatch($service, 'file');
        self::assertCount(1, $this->dispatched);
        self::assertSame(['req-1', 'file'], [$this->dispatched[0]->requestId, $this->dispatched[0]->step]);
        self::assertSame(SmsExportQueue::RUN_STEP_TIMEOUT, $this->dispatched[0]->timeout);
        self::assertSame([], $failed->calls);
    }

    /** @return array{0: SmsRunService, 1: object} */
    private function service(): array
    {
        $failed = new class { public array $calls = []; };
        $service = new class($failed) extends SmsRunService
        {
            public function __construct(private object $failed) {}

            public function fail(string $requestId, string $job, string $message): void { $this->failed->calls[] = [$requestId, $job, $message]; }
        };

        return [$service, $failed];
    }

    private function dispatch(SmsRunService $service, string $step): void
    {
        $method = new ReflectionMethod(SmsRunService::class, 'dispatch');
        $method->setAccessible(true);
        $method->invoke($service, 'req-1', $step);
    }
}
