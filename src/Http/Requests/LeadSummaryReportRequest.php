<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeadSummaryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'creditor' => ['nullable', 'string', 'max:120'],
            'debt_buyer' => ['nullable', 'string', 'max:120'],
            'offer_status' => ['nullable', 'string', 'max:120'],
            'account' => ['nullable', 'string', 'max:120'],
            'min_verified' => ['nullable', 'numeric'],
            'max_verified' => ['nullable', 'numeric'],
            'min_settlement' => ['nullable', 'numeric'],
            'max_settlement' => ['nullable', 'numeric'],
            'export' => ['nullable', 'in:csv'],
        ];
    }
}
