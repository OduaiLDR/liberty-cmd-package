<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LdrPastDueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'contact_id' => ['nullable', 'string', 'max:100'],
            'trans_type' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'string', 'max:20'],
            'cancelled' => ['nullable', 'string', 'max:20'],
            'process_from' => ['nullable', 'date'],
            'process_to' => ['nullable', 'date'],
            'cleared_from' => ['nullable', 'date'],
            'cleared_to' => ['nullable', 'date'],
            'returned_from' => ['nullable', 'date'],
            'returned_to' => ['nullable', 'date'],
            'amount_min' => ['nullable', 'numeric'],
            'amount_max' => ['nullable', 'numeric'],
        ];
    }
}
