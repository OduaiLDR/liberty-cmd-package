<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WelcomePacketReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'client' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:255'],
            'llg_id' => ['nullable', 'string', 'max:100'],
            'cleared_from' => ['nullable', 'date'],
            'cleared_to' => ['nullable', 'date'],
        ];
    }
}
