<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cmd.reports.marketing_report') ?? false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['mail', 'data', 'sms'])],
            'invoice_number' => ['required', 'string', 'max:100'],
            'cost' => ['required', 'regex:/^\\d{1,7}(?:\\.\\d{1,2})?$/'],
            'week' => ['required_if:kind,sms', 'nullable', 'date_format:Y-m-d'],
            'drop_name' => ['required_unless:kind,sms', 'nullable', 'string', 'max:255'],
        ];
    }
}
