<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnrollmentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'range' => ['nullable', 'in:all,today,7,30,this_month,last_month,custom'],
            'date_by' => ['nullable', 'in:submitted,welcome_call,payment'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'agent' => ['nullable', 'string', 'max:100'],
            'negotiator' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:2'],
            'enrollment_status' => ['nullable', 'string', 'max:100'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'length_min' => ['nullable', 'integer', 'min:0'],
            'length_max' => ['nullable', 'integer', 'min:0'],
            'company' => ['nullable', 'in:ldr,progress'],
        ];
    }
}
