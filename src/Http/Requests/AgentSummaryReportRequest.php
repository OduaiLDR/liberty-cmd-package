<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentSummaryReportRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'avg_debt_min' => ['nullable', 'numeric', 'min:0'],
            'avg_debt_max' => ['nullable', 'numeric', 'min:0'],
            'leads_min' => ['nullable', 'integer', 'min:0'],
            'leads_max' => ['nullable', 'integer', 'min:0'],
            'assigned_min' => ['nullable', 'integer', 'min:0'],
            'assigned_max' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
