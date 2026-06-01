<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Services;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Exceptions\UnsupportedPmodActionException;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Tests\TestCase;

final class PmodDispatcherTest extends TestCase
{
    public function test_it_reports_supported_action_types_and_dispatches_to_the_matching_handler(): void
    {
        $result = new PmodResult('updated', 'handled');

        $handler = new class($result) implements PmodActionHandler {
            public function __construct(private readonly PmodResult $result)
            {
            }

            public function actionType(): PmodActionType
            {
                return PmodActionType::SKIP_PAYMENT;
            }

            public function handle(PmodWorkItem $workItem): PmodResult
            {
                return $this->result;
            }
        };

        $dispatcher = new PmodDispatcher([$handler]);
        $workItem = $this->makeWorkItem(['actionType' => PmodActionType::SKIP_PAYMENT]);

        self::assertTrue($dispatcher->supports(PmodActionType::SKIP_PAYMENT));
        self::assertFalse($dispatcher->supports(PmodActionType::CHANGE_PAYMENT));
        self::assertSame([PmodActionType::SKIP_PAYMENT->value], $dispatcher->supportedActionTypes());
        self::assertSame($result, $dispatcher->dispatch($workItem));
    }

    public function test_it_throws_when_no_handler_is_registered_for_the_work_item_action(): void
    {
        $dispatcher = new PmodDispatcher();
        $workItem = $this->makeWorkItem(['actionType' => PmodActionType::RESCHEDULE_ALL_PAYMENTS]);

        $this->expectException(UnsupportedPmodActionException::class);
        $this->expectExceptionMessage('PMOD action [reschedule_all_payments] is not implemented yet.');

        $dispatcher->dispatch($workItem);
    }
}
