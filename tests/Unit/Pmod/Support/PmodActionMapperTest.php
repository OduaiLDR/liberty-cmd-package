<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Support;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodActionMapper;
use Cmd\Reports\Tests\TestCase;

final class PmodActionMapperTest extends TestCase
{
    public function test_it_accepts_all_documented_pmod_enum_values(): void
    {
        foreach ($this->documentedPmodActionTypes() as $actionType) {
            self::assertSame($actionType, PmodActionMapper::fromLabel($actionType->value));
        }
    }

    /**
     * @return list<PmodActionType>
     */
    private function documentedPmodActionTypes(): array
    {
        return [
            PmodActionType::ADDITIONAL_PAYMENT,
            PmodActionType::CHANGE_PAYMENT,
            PmodActionType::SKIP_PAYMENT,
            PmodActionType::RESCHEDULE_ALL_PAYMENTS,
            PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS,
            PmodActionType::VOID_SETTLEMENT,
            PmodActionType::PMOD_INCREASE_PAYMENTS,
            PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            PmodActionType::PMOD_EXTEND_PROGRAM,
            PmodActionType::PMOD_LUMP_SUM,
            PmodActionType::ADD_BANK_ACCOUNT,
            PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            PmodActionType::CAPTURE_SPONSOR_BANKING,
            PmodActionType::PAYMENT_REFUND,
        ];
    }
}
