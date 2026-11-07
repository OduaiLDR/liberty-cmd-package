<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class SmsQueueAheadTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['sms-exports' => ['queue' => 'tu-export']]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    private function ahead(int|\Throwable $size): ?int
    {
        Container::getInstance()->instance('queue', new class($size)
        {
            public array $asked = [];

            public function __construct(private int|\Throwable $size) {}

            public function size(?string $queue = null): int
            {
                $this->asked[] = $queue;
                if ($this->size instanceof \Throwable) throw $this->size;

                return $this->size;
            }
        });
        Facade::clearResolvedInstances();
        $service = (new ReflectionClass(SmsRunService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SmsRunService::class, 'queueAhead'))->invoke($service);
    }

    public function test_its_own_job_is_not_counted_as_ahead(): void
    {
        $this->assertSame(0, $this->ahead(1));
        $this->assertSame(3, $this->ahead(4));
        $this->assertSame(['tu-export'], Container::getInstance()->make('queue')->asked);
    }

    public function test_an_empty_queue_never_goes_negative(): void
    {
        $this->assertSame(0, $this->ahead(0));
    }

    public function test_a_queue_failure_leaves_it_unknown_and_never_breaks_the_page(): void
    {
        $this->assertNull($this->ahead(new RuntimeException('queue down')));
    }
}
