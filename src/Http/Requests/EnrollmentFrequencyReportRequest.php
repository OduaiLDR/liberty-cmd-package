<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnrollmentFrequencyReportRequest extends FormRequest
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
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date'],
        ];
    }
}
