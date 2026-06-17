<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaderboardReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'in:Deals Enrolled,Debt Enrolled,Same Month Pay,Conversion Ratio,Cancellation Ratio,NSF Ratio,Active Clients,Individual Debt'],
            'period' => ['nullable', 'string', 'in:Daily,Weekly,Monthly,Quarterly,Yearly'],
            'export' => ['nullable', 'in:csv'],
        ];
    }
}
