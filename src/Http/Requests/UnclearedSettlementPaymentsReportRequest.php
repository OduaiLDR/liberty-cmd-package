<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnclearedSettlementPaymentsReportRequest extends FormRequest
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
            'process_date_from' => ['nullable', 'date'],
            'process_date_to' => ['nullable', 'date'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
