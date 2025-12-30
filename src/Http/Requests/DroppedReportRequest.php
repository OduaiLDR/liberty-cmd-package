<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DroppedReportRequest extends FormRequest
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
            'client' => ['nullable', 'string', 'max:255'],
            'dropped_reason' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'enrolled_from' => ['nullable', 'date'],
            'enrolled_to' => ['nullable', 'date'],
            'dropped_from' => ['nullable', 'date'],
            'dropped_to' => ['nullable', 'date'],
            'days_enrolled_min' => ['nullable', 'integer', 'min:0'],
            'days_enrolled_max' => ['nullable', 'integer', 'min:0'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
