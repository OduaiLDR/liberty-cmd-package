<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MailDropExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pks' => ['required', 'array', 'min:1'],
            'pks.*' => ['required', 'integer'],
        ];
    }
}
