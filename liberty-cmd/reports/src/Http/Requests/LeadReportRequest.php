<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeadReportRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'agent' => ['nullable', 'string', 'max:100'],
            'data_source' => ['nullable', 'string', 'max:150'],
            'debt_tier' => ['nullable', 'string', 'max:50'],
            'status_type' => ['nullable', 'in:all,active,cancels,nsfs,not_closed'],
        ];
    }
}
