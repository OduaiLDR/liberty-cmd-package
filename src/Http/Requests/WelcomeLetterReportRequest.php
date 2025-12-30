<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WelcomeLetterReportRequest extends FormRequest
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
            'frequency' => ['nullable', 'string', 'in:all,weekly,bi-weekly,semi-monthly,monthly'],
            'enrolled_from' => ['nullable', 'date'],
            'enrolled_to' => ['nullable', 'date'],
            'payment_from' => ['nullable', 'date'],
            'payment_to' => ['nullable', 'date'],
            'debt_min' => ['nullable', 'numeric', 'min:0'],
            'debt_max' => ['nullable', 'numeric', 'min:0'],
            'payment_min' => ['nullable', 'numeric', 'min:0'],
            'payment_max' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
