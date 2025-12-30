<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EpfPaidReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'range' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],

            'llg_id' => ['nullable', 'string', 'max:50'],
            'state' => ['nullable', 'string', 'max:50'],
            'tranche' => ['nullable', 'string', 'max:50'],
            'creditor' => ['nullable', 'string', 'max:255'],
            'settlement_id' => ['nullable', 'string', 'max:50'],
            'payment_number' => ['nullable', 'string', 'max:50'],
            'confirmation' => ['nullable', 'string', 'max:255'],
        ];
    }
}
