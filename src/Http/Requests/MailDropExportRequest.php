<?php

namespace Cmd\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MailDropExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cmd.reports.mail_drop_export') ?? false;
    }

    public function rules(): array
    {
        return [
            'target' => ['required', 'integer', 'min:1', 'max:10000000'],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
