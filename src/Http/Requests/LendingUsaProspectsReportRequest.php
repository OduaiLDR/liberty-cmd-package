<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LendingUsaProspectsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'llg_id' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:50'],
            'first_payment_from' => ['nullable', 'date'],
            'first_payment_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'balance_min' => ['nullable', 'numeric', 'min:0'],
            'balance_max' => ['nullable', 'numeric', 'min:0'],
            'payments_min' => ['nullable', 'numeric', 'min:0'],
            'payments_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
