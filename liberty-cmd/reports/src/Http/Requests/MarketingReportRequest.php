<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarketingReportRequest extends FormRequest
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
            'range' => ['nullable', 'in:all,today,7,30,this_month,last_month'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'drop_name' => ['nullable', 'string', 'max:255'],
            'debt_tier' => ['nullable', 'string', 'max:255'],
            'drop_type' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'data_type' => ['nullable', 'string', 'max:255'],
            'mail_style' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:255'],
        ];
    }
}
