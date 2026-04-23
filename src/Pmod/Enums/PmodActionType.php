<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Enums;

enum PmodActionType: string
{
    case ADDITIONAL_PAYMENT = 'additional_payment';
    case CHANGE_PAYMENT = 'change_payment';
    case SKIP_PAYMENT = 'skip_payment';
    case RESCHEDULE_ALL_PAYMENTS = 'reschedule_all_payments';
    case INCREASE_ALL_FUTURE_PAYMENTS = 'increase_all_future_payments';
    case VOID_SETTLEMENT = 'void_settlement';
    case PMOD_INCREASE_PAYMENTS = 'pmod_increase_payments';
    case PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM = 'pmod_increase_payments_and_extend_program';
    case PMOD_EXTEND_PROGRAM = 'pmod_extend_program';
    case PMOD_LUMP_SUM = 'pmod_lump_sum';
    case ADD_BANK_ACCOUNT = 'add_bank_account';
    case ADD_CREDITOR_AND_EXTEND_PROGRAM = 'add_creditor_and_extend_program';
    case ADD_CREDITOR_AND_INCREASE_PAYMENT = 'add_creditor_and_increase_payment';
    case REMOVE_CREDITOR_AND_DECREASE_TERM = 'remove_creditor_and_decrease_term';
    case REMOVE_CREDITOR_AND_DECREASE_PAYMENT = 'remove_creditor_and_decrease_payment';
    case CAPTURE_SPONSOR_BANKING = 'capture_sponsor_banking';
    case SETTLEMENT_APPROVAL = 'settlement_approval';
    case PAYMENT_REFUND = 'payment_refund';
    case UPDATE_LAST_LOGIN_CONTACT_LISTS = 'update_last_login_contact_lists';
    case ADD_SPONSOR_CONTACT = 'add_sponsor_contact';
    case LINK_SPONSOR = 'link_sponsor';
    case SETTLEMENT_AUTHORIZATION = 'settlement_authorization';
    case CAPTURE_CLIENT_SUBMISSION = 'capture_client_submission';
    case ASSIGN_NSF_LEAD = 'assign_nsf_lead';
    case ASSIGN_RETENTION_LEAD = 'assign_retention_lead';
}
