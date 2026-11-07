<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SmsApprovalHoldTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        $service = (new ReflectionClass(SmsRunService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SmsRunService::class, $method))->invoke($service, ...$arguments);
    }

    public function test_a_hold_is_saved_for_the_pages_polling_when_its_409_cant_arrive(): void
    {
        try {
            $this->approveCheck(new SmsFrozenEligibilityHold('A frozen SMS member is contacted.'));
        } catch (HttpException) {
        }
        $this->assertSame('A frozen SMS member is contacted.', $this->call('approvalHold', 'ID'));
        $this->assertNull($this->call('approvalHold', 'another-run'));
        $this->call('forgetApprovalHold', 'id');
        $this->assertNull($this->call('approvalHold', 'id'));
    }

    public function test_a_real_fault_saves_no_hold(): void
    {
        try {
            $this->approveCheck(new RuntimeException('database went away'));
        } catch (RuntimeException) {
        }
        $this->assertNull($this->call('approvalHold', 'id'));
    }

    public function test_a_broken_cache_changes_nothing_about_the_stop(): void
    {
        Cache::shouldReceive('store')->andThrow(new RuntimeException('disk full'));
        try {
            $this->approveCheck(new SmsFrozenEligibilityHold('A frozen SMS member is contacted.'));
            $this->fail('Approval should have stopped.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertNull($this->call('approvalHold', 'id'));
        $this->call('forgetApprovalHold', 'id');
    }

    private function approveCheck(\Throwable $error): void
    {
        $fake = new class($error) extends SmsRecordExportRepository
        {
            public function __construct(private \Throwable $error) {}

            public function assertFrozen(string $requestId, int $expected, bool $pendingIds = false): void
            {
                throw $this->error;
            }
        };
        Container::getInstance()->instance(SmsRecordExportRepository::class, $fake);
        $service = (new ReflectionClass(SmsRunService::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(SmsRunService::class, 'assertFrozenForApproval'))->invoke($service, 'id', 3, true);
    }

    public function test_a_member_who_changed_stops_approval_as_a_conflict_with_its_reason(): void
    {
        try {
            $this->approveCheck(new SmsFrozenEligibilityHold('A frozen SMS member is contacted, suppressed, invalid or has a changed phone.'));
            $this->fail('Approval should have stopped.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertStringContainsString('frozen SMS member is contacted', $error->getMessage());
        }
    }

    public function test_a_real_fault_stays_a_server_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database went away');
        $this->approveCheck(new RuntimeException('database went away'));
    }
}
