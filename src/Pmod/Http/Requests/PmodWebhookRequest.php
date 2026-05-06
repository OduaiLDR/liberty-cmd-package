<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Http\Requests;

use Cmd\Reports\Pmod\Parsing\PmodApprovalParser;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

final class PmodWebhookRequest extends FormRequest
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $parserErrors = [];

    /**
     * @var array<string, mixed>
     */
    private array $normalizedPayload = [];

    /**
     * @var array<string, mixed>
     */
    private array $rawPayloadSnapshot = [];

    public function authorize(): bool
    {
        $configuredToken = trim((string) config('services.pmod.webhook_token', ''));

        if ($configuredToken === '') {
            return app()->environment(['local', 'testing']);
        }

        $providedToken = trim((string) ($this->bearerToken() ?: $this->header('X-PMOD-Token', '')));

        return $providedToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string'],
            'action' => ['required', 'string'],
            'requested_by' => ['required', 'string'],
            'company' => ['nullable', 'string'],
            'settlement_id' => ['nullable', 'string'],
            'settlement_ids' => ['nullable', 'array'],
            'settlement_ids.*' => ['string'],
            'amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['regex:/^-?\d+\.\d{2}$/'],
            'increase_amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'total_amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'original_date' => ['nullable', 'date_format:Y-m-d'],
            'original_dates' => ['nullable', 'array'],
            'original_dates.*' => ['date_format:Y-m-d'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'target_dates' => ['nullable', 'array'],
            'target_dates.*' => ['date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'extended_start_date' => ['nullable', 'date_format:Y-m-d'],
            'extended_end_date' => ['nullable', 'date_format:Y-m-d'],
            'extended_amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'frequency' => ['nullable', 'string'],
            'void_settlements' => ['nullable', 'boolean'],
            'banking_update' => ['nullable', 'array'],
            'banking' => ['nullable', 'array'],
            'bank_account' => ['nullable', 'array'],
            'creditor_change' => ['nullable', 'array'],
            'creditor' => ['nullable', 'array'],
            'creditor_update' => ['nullable', 'array'],
            'sponsor_update' => ['nullable', 'array'],
            'sponsor' => ['nullable', 'array'],
            'sponsor_banking' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var PmodApprovalParser $parser */
        $parser = app(PmodApprovalParser::class);

        if ($this->containsStructuredFields()) {
            $this->rawPayloadSnapshot = $this->all();
            $parsed = $parser->parsePayload($this->all());
        } else {
            $rawText = (string) $this->getContent();
            $this->rawPayloadSnapshot = ['raw_text' => $rawText];
            $parsed = $parser->parseRawText($rawText);
        }

        $this->parserErrors = $parsed['errors'];
        $this->normalizedPayload = $this->withHeaderFallbacks($this->withStructuredPayload($parsed['data']));
        $this->merge($this->normalizedPayload);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->parserErrors as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Unauthorized PMOD webhook request.',
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedPayload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawPayloadSnapshot(): array
    {
        return $this->rawPayloadSnapshot;
    }

    private function containsStructuredFields(): bool
    {
        foreach ($this->structuredFieldKeys() as $key) {
            if ($this->offsetExists($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function withStructuredPayload(array $normalizedPayload): array
    {
        foreach ($this->structuredFieldKeys() as $key) {
            if (!$this->offsetExists($key) || array_key_exists($key, $normalizedPayload)) {
                continue;
            }

            $value = $this->input($key);
            if (is_scalar($value) || is_array($value) || $value === null) {
                $normalizedPayload[$key] = $value;
            }
        }

        return $normalizedPayload;
    }

    /**
     * @return array<string, mixed>
     */
    private function withHeaderFallbacks(array $normalizedPayload): array
    {
        if (empty($normalizedPayload['company'])) {
            $company = trim((string) ($this->header('X-Company') ?: $this->header('X-Portal-Source')));

            if ($company !== '') {
                $normalizedPayload['company'] = strtoupper($company);
            }
        }

        if (empty($normalizedPayload['requested_by'])) {
            $requestedBy = trim((string) ($this->input('portal_user') ?: $this->input('requestedBy')));

            if ($requestedBy !== '') {
                $normalizedPayload['requested_by'] = $requestedBy;
            }
        }

        return $normalizedPayload;
    }

    /**
     * @return list<string>
     */
    private function structuredFieldKeys(): array
    {
        return [
            'customer_id',
            'contact_id',
            'customerId',
            'Customer Id',
            'PMOD Approval For Customer Id',
            'settlement_id',
            'settlementId',
            'settlement_ids',
            'void_settlement',
            'voidSettlement',
            'action',
            'Action',
            'amount',
            'amounts',
            'payment_amount',
            'new_amount',
            'increase_amount',
            'increaseAmount',
            'total_amount',
            'totalAmount',
            'original_date',
            'originalDate',
            'original_dates',
            'originalDates',
            'target_date',
            'new_date',
            'reschedule_date',
            'payment_date',
            'targetDate',
            'target_dates',
            'targetDates',
            'start_date',
            'startDate',
            'end_date',
            'endDate',
            'extended_months',
            'extendedMonths',
            'extended_amount',
            'extendedAmount',
            'frequency',
            'requested_by',
            'requestedBy',
            'portal_user',
            'banking_update',
            'banking',
            'bank_account',
            'account_number',
            'routing_number',
            'account_type',
            'bank_name',
            'account_holder_name',
            'name_on_account',
            'creditor_change',
            'creditor',
            'creditor_update',
            'creditor_name',
            'creditor_id',
            'balance',
            'settlement_amount',
            'months_to_extend',
            'months_to_decrease',
            'sponsor_update',
            'sponsor',
            'sponsor_banking',
            'sponsor_id',
            'sponsor_name',
            'sponsor_account_number',
            'sponsor_routing_number',
            'sponsor_account_type',
            'sponsor_bank_name',
        ];
    }
}
