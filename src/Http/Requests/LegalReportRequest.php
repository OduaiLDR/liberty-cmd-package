<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LegalReportRequest extends FormRequest
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
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:50'],
            'enrolled' => ['nullable', 'string', 'max:20'],
            'has_summons' => ['nullable', 'string', 'max:20'],
            'settled' => ['nullable', 'string', 'max:20'],
            'debt_buyer' => ['nullable', 'string', 'max:255'],
            'creditor_id' => ['nullable', 'string', 'max:100'],
            'plan_id' => ['nullable', 'string', 'max:100'],
            'summons_from' => ['nullable', 'date'],
            'summons_to' => ['nullable', 'date'],
            'answer_from' => ['nullable', 'date'],
            'answer_to' => ['nullable', 'date'],
            'poa_from' => ['nullable', 'date'],
            'poa_to' => ['nullable', 'date'],
            'settlement_from' => ['nullable', 'date'],
            'settlement_to' => ['nullable', 'date'],
            'dob_from' => ['nullable', 'date'],
            'dob_to' => ['nullable', 'date'],
            'verified_min' => ['nullable', 'numeric'],
            'verified_max' => ['nullable', 'numeric'],
            'has_notes' => ['nullable', 'string', 'max:10'],
        ];
    }
}
