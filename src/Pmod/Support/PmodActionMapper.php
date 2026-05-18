<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use InvalidArgumentException;

final class PmodActionMapper
{
    public static function fromLabel(string $action): PmodActionType
    {
        $normalized = strtoupper(trim($action));
        $normalized = preg_replace('/^ACTION\s*:\s*/i', '', $normalized) ?? $normalized;

        return match ($normalized) {
            'MAKE AN ADDITIONAL PAYMENT', 'MAKE ADDITIONAL PAYMENT', 'ADDITIONAL PAYMENT', 'ADDITIONAL_PAYMENT' => PmodActionType::ADDITIONAL_PAYMENT,
            'RESCHEDULE PAYMENT', 'CHANGE PAYMENT', 'CHANGE_PAYMENT' => PmodActionType::CHANGE_PAYMENT,
            'SKIP PAYMENT', 'SKIP_PAYMENT' => PmodActionType::SKIP_PAYMENT,
            'RESCHEDULE ALL FUTURE PAYMENTS', 'RESCHEDULE ALL PAYMENTS', 'RESCHEDULE_ALL_PAYMENTS' => PmodActionType::RESCHEDULE_ALL_PAYMENTS,
            'INCREASE ALL FUTURE PAYMENTS', 'INCREASE_ALL_FUTURE_PAYMENTS' => PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS,
            'VOID SETTLEMENT', 'VOID_SETTLEMENT' => PmodActionType::VOID_SETTLEMENT,
            'INCREASE PAYMENTS', 'PMOD_INCREASE_PAYMENTS' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'INCREASE PAYMENTS & EXTEND PROGRAM', 'INCREASE PAYMENT & EXTEND PROGRAM', 'PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'EXTEND PROGRAM', 'PMOD_EXTEND_PROGRAM' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'MAKING AN ADDITIONAL DEPOSIT', 'MAKE ADDITIONAL DEPOSIT', 'LUMP SUM', 'LUMP_SUM', 'PMOD_LUMP_SUM' => PmodActionType::PMOD_LUMP_SUM,
            'UPDATED BANKING', 'ADD_BANK_ACCOUNT' => PmodActionType::ADD_BANK_ACCOUNT,
            'ADD CREDITOR AND EXTEND PROGRAM', 'ADD_CREDITOR_AND_EXTEND_PROGRAM' => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'ADD CREDITOR AND INCREASE PAYMENT', 'ADD_CREDITOR_AND_INCREASE_PAYMENT' => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'REMOVE CREDITOR AND DECREASE TERM', 'REMOVE_CREDITOR_AND_DECREASE_TERM' => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'REMOVE CREDITOR AND DECREASE PAYMENT', 'REMOVE_CREDITOR_AND_DECREASE_PAYMENT' => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'UPDATED SPONSOR BANKING', 'CAPTURE_SPONSOR_BANKING' => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'SETTLEMENT APPROVAL', 'SETTLEMENT_APPROVAL' => PmodActionType::SETTLEMENT_APPROVAL,
            'PAYMENT REFUND', 'PAYMENT_REFUND' => PmodActionType::PAYMENT_REFUND,
            default => throw new InvalidArgumentException(sprintf('Unsupported PMOD action label [%s].', $action)),
        };
    }
}
