<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetentionCommissionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'client' => ['nullable', 'string', 'max:255'],
            'retention_agent' => ['nullable', 'string', 'max:255'],
            'immediate_results' => ['nullable', 'string', 'max:255'],
            'retention_date_from' => ['nullable', 'date'],
            'retention_date_to' => ['nullable', 'date'],
            'dropped_date_from' => ['nullable', 'date'],
            'dropped_date_to' => ['nullable', 'date'],
            'reconsideration_date_from' => ['nullable', 'date'],
            'reconsideration_date_to' => ['nullable', 'date'],
            'retained_date_from' => ['nullable', 'date'],
            'retained_date_to' => ['nullable', 'date'],
            'retention_payment_date_from' => ['nullable', 'date'],
            'retention_payment_date_to' => ['nullable', 'date'],
            'enrolled_debt_min' => ['nullable', 'numeric', 'min:0'],
            'enrolled_debt_max' => ['nullable', 'numeric', 'min:0'],
            'cleared_payments_min' => ['nullable', 'integer', 'min:0'],
            'cleared_payments_max' => ['nullable', 'integer', 'min:0'],
            'commission_t1_min' => ['nullable', 'numeric', 'min:0'],
            'commission_t1_max' => ['nullable', 'numeric', 'min:0'],
            'commission_t2_min' => ['nullable', 'numeric', 'min:0'],
            'commission_t2_max' => ['nullable', 'numeric', 'min:0'],
            'commission_t3_min' => ['nullable', 'numeric', 'min:0'],
            'commission_t3_max' => ['nullable', 'numeric', 'min:0'],
            'cancel_request_date_from' => ['nullable', 'date'],
            'cancel_request_date_to' => ['nullable', 'date'],
        ];
    }
}
