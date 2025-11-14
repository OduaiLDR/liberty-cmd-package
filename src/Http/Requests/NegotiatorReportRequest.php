<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NegotiatorReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'date_field' => ['nullable', 'in:payment,welcome_call,submitted'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'negotiator' => ['nullable', 'string', 'max:150'],
            'ngo' => ['nullable', 'string', 'max:150'],
            'enrollment_status' => ['nullable', 'string', 'max:150'],
            'assignment_status' => ['nullable', 'string', 'max:150'],
            'ready_flag' => ['nullable', 'in:ready,not_ready'],
            'creditor' => ['nullable', 'string', 'max:150'],
            'collection_company' => ['nullable', 'string', 'max:150'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'follow_up_from' => ['nullable', 'date_format:Y-m-d'],
            'follow_up_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:follow_up_from'],
            'ready_from' => ['nullable', 'date_format:Y-m-d'],
            'ready_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:ready_from'],
            'settlement_from' => ['nullable', 'date_format:Y-m-d'],
            'settlement_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:settlement_from'],
            'last_payment_from' => ['nullable', 'date_format:Y-m-d'],
            'last_payment_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_payment_from'],
            'report_type' => ['nullable', 'in:ready,not_ready,settled'],
        ];
    }
}
