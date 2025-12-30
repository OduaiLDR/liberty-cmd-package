<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesAdminReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'agent' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'string', 'max:255'],
            'llg_id' => ['nullable', 'string', 'max:100'],
            'payment_date_from' => ['nullable', 'date'],
            'payment_date_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'program_length_min' => ['nullable', 'numeric', 'min:0'],
            'program_length_max' => ['nullable', 'numeric', 'min:0'],
            'payments_min' => ['nullable', 'numeric', 'min:0'],
            'payments_max' => ['nullable', 'numeric', 'min:0'],
            'cancel_from' => ['nullable', 'date'],
            'cancel_to' => ['nullable', 'date'],
            'nsf_from' => ['nullable', 'date'],
            'nsf_to' => ['nullable', 'date'],
        ];
    }
}
