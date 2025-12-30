<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancellationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'id' => ['nullable', 'string', 'max:100'],
            'enrollment_plan' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'in:all,ldr,plaw'],
            'enrolled_from' => ['nullable', 'date'],
            'enrolled_to' => ['nullable', 'date'],
            'dropped_from' => ['nullable', 'date'],
            'dropped_to' => ['nullable', 'date'],
            'with_settlements' => ['nullable', 'boolean'],
        ];
    }
}
