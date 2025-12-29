<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LlgExecAdminReportRequest extends FormRequest
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
            'client' => ['nullable', 'string', 'max:150'],
            'source' => ['nullable', 'string', 'max:150'],
            'employee_name' => ['nullable', 'string', 'max:150'],
            'payment_type' => ['nullable', 'string', 'max:150'],
        ];
    }
}
