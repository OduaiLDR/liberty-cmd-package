<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactAnalysisReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'data_source' => ['nullable', 'string', 'max:150'],
            'range' => ['nullable', 'string', 'max:20'],
            'export' => ['nullable', 'in:csv'],
            'chart_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'chart_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'chart_min_debt' => ['nullable', 'numeric', 'min:0'],
            'chart_max_debt' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
