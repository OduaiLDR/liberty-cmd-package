<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettlementAdminReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'llg_id' => ['nullable', 'string', 'max:100'],
            'settlement_from' => ['nullable', 'date'],
            'settlement_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
