<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientSubmissionReportRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'first_payment_from' => ['nullable', 'date'],
            'first_payment_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'program_length_min' => ['nullable', 'numeric', 'min:0'],
            'program_length_max' => ['nullable', 'numeric', 'min:0'],
            'monthly_deposit_min' => ['nullable', 'numeric', 'min:0'],
            'monthly_deposit_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
