<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconsiderationReportRequest extends FormRequest
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
            'dropped_by' => ['nullable', 'string', 'max:255'],
            'dropped_reason' => ['nullable', 'string', 'max:255'],
            'retention_agent' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'active_status' => ['nullable', 'string', 'max:255'],
            'current_status' => ['nullable', 'string', 'max:255'],
            'enrolled_from' => ['nullable', 'date'],
            'enrolled_to' => ['nullable', 'date'],
            'dropped_from' => ['nullable', 'date'],
            'dropped_to' => ['nullable', 'date'],
            'status_date_from' => ['nullable', 'date'],
            'status_date_to' => ['nullable', 'date'],
            'cancel_request_date_from' => ['nullable', 'date'],
            'cancel_request_date_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
