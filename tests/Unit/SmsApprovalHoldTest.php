<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Container\Container;
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
        Container::setInstance(new Container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
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
