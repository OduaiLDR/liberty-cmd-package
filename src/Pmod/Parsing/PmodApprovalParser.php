<?php

namespace Cmd\Reports\Pmod\Parsing;

class PmodApprovalParser
{
    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    public function parsePayload(array $payload): array
    {
        $mapped = [
            'customer_id' => $this->getFirst($payload, ['customer_id', 'contact_id', 'customerId', 'contactId', 'Customer Id', 'PMOD Approval For Customer Id']),
            'action' => $this->getFirst($payload, ['action', 'Action']),
            'requested_by' => $this->getFirst($payload, ['requested_by', 'requestedBy', 'portal_user', 'User']),
            'company' => $this->getFirst($payload, ['company', 'Company']),
            'settlement_id' => $this->getFirst($payload, ['settlement_id', 'settlementId', 'Settlement ID']),
            'settlement_ids' => $this->getFirst($payload, ['settlement_ids', 'settlementIds']),
            'void_settlement' => $this->getFirst($payload, ['void_settlement', 'voidSettlement', 'Void Settlement']),
            'amounts' => $this->getFirst($payload, ['amounts', 'Amounts', 'amount', 'payment_amount', 'new_amount', 'Payment Amount', 'Lump Sum Amount']),
            'increase_amount' => $this->getFirst($payload, ['increase_amount', 'increaseAmount', 'Increase Payment Amount']),
            'total_amount' => $this->getFirst($payload, ['total_amount', 'totalAmount', 'Total Payment Amount', 'Total Refund']),
            'original_dates' => $this->getFirst($payload, ['original_dates', 'originalDates', 'Original Scheduled Date']),
            'target_dates' => $this->getFirst($payload, ['target_dates', 'targetDates', 'target_date', 'new_date', 'reschedule_date', 'payment_date', 'New Scheduled Date', 'Add Payment Date', 'Date']),
            'start_date' => $this->getFirst($payload, ['start_date', 'startDate', 'Start Date']),
            'end_date' => $this->getFirst($payload, ['end_date', 'endDate', 'End Date']),
            'extended_months' => $this->getFirst($payload, ['extended_months', 'extendedMonths', 'Extended Months']),
            'extended_amount' => $this->getFirst($payload, ['extended_amount', 'extendedAmount', 'Extended Amount']),
            'frequency' => $this->getFirst($payload, ['frequency', 'Frequency', 'Payment Frequency', 'New Frequency']),
            'banking_update' => $this->getStructuredPayload(
                $payload,
                ['banking_update', 'banking', 'bank_account'],
                ['account_number', 'routing_number', 'account_type', 'bank_name', 'account_holder_name', 'name_on_account'],
            ),
            'creditor_change' => $this->getStructuredPayload(
                $payload,
                ['creditor_change', 'creditor', 'creditor_update'],
                ['creditor_name', 'creditor_id', 'account_number', 'balance', 'settlement_amount', 'months_to_extend', 'months_to_decrease', 'amount'],
            ),
            'sponsor_update' => $this->getStructuredPayload(
                $payload,
                ['sponsor_update', 'sponsor', 'sponsor_banking'],
                ['sponsor_id', 'sponsor_name', 'sponsor_account_number', 'sponsor_routing_number', 'sponsor_account_type', 'sponsor_bank_name'],
            ),
        ];

        return $this->normalize($mapped);
    }

    /**
     * @return array{data: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    public function parseRawText(string $rawText): array
    {
        return $this->parsePayload($this->extractRawPairs($rawText));
    }

    /**
     * @param array<string, mixed> $mapped
     * @return array{data: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    private function normalize(array $mapped): array
    {
        $errors = [];

        $customerId = $this->normalizeCustomerId($mapped['customer_id'] ?? null);
        $action = $this->normalizeText($mapped['action'] ?? null);
        $requestedBy = $this->normalizeText($mapped['requested_by'] ?? null);
        $company = $this->normalizeCompany($mapped['company'] ?? null);

        $settlementIds = [];
        $settlementIds = array_merge(
            $settlementIds,
            $this->normalizeSettlementIds($this->normalizeStringList($mapped['settlement_id'] ?? null, true), 'settlement_id', $errors),
            $this->normalizeSettlementIds($this->normalizeStringList($mapped['settlement_ids'] ?? null, true), 'settlement_ids', $errors),
            $this->normalizeSettlementIds($this->normalizeStringList($mapped['void_settlement'] ?? null, true), 'settlement_ids', $errors),
        );
        $settlementIds = array_values(array_unique($settlementIds));

        $amounts = $this->normalizeAmountList($this->normalizeStringList($mapped['amounts'] ?? null), 'amounts', $errors);
        $amount = $amounts[0] ?? null;

        $increaseAmountInput = $this->normalizeText($mapped['increase_amount'] ?? null);
        $increaseAmount = $this->normalizeAmount($increaseAmountInput);
        if ($increaseAmountInput !== null && $increaseAmount === null) {
            $errors['increase_amount'][] = 'The increase_amount must be a valid amount.';
        }

        $totalAmountInput = $this->normalizeText($mapped['total_amount'] ?? null);
        $totalAmount = $this->normalizeAmount($totalAmountInput);
        if ($totalAmountInput !== null && $totalAmount === null) {
            $errors['total_amount'][] = 'The total_amount must be a valid amount.';
        }

        $originalDates = $this->normalizeDateList($this->normalizeStringList($mapped['original_dates'] ?? null), 'original_dates', $errors);
        $targetDates = $this->normalizeDateList($this->normalizeStringList($mapped['target_dates'] ?? null), 'target_dates', $errors);

        $startDateInput = $this->normalizeText($mapped['start_date'] ?? null);
        $startDate = $this->normalizeDate($startDateInput);
        if ($startDateInput !== null && $startDate === null) {
            $errors['start_date'][] = 'The start_date must be in YYYY-MM-DD or MM/DD/YYYY format.';
        }

        $endDateInput = $this->normalizeText($mapped['end_date'] ?? null);
        $endDate = $this->normalizeDate($endDateInput);
        if ($endDateInput !== null && $endDate === null) {
            $errors['end_date'][] = 'The end_date must be in YYYY-MM-DD or MM/DD/YYYY format.';
        }

        $extendedMonths = $this->normalizeDateList($this->normalizeStringList($mapped['extended_months'] ?? null, true), 'extended_months', $errors);
        if (($mapped['extended_months'] ?? null) !== null && count($extendedMonths) !== 2) {
            $errors['extended_months'][] = 'The extended_months field must contain exactly two dates.';
        }

        $extendedAmountInput = $this->normalizeText($mapped['extended_amount'] ?? null);
        $extendedAmount = $this->normalizeAmount($extendedAmountInput);
        if ($extendedAmountInput !== null && $extendedAmount === null) {
            $errors['extended_amount'][] = 'The extended_amount must be a valid amount.';
        }

        return [
            'data' => [
                'customer_id' => $customerId,
                'action' => $action,
                'requested_by' => $requestedBy,
                'company' => $company,
                'settlement_id' => $settlementIds[0] ?? null,
                'settlement_ids' => $settlementIds,
                'amount' => $amount,
                'amounts' => $amounts,
                'increase_amount' => $increaseAmount,
                'total_amount' => $totalAmount,
                'original_date' => $originalDates[0] ?? null,
                'original_dates' => $originalDates,
                'target_date' => $targetDates[0] ?? null,
                'target_dates' => $targetDates,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'extended_start_date' => $extendedMonths[0] ?? null,
                'extended_end_date' => $extendedMonths[1] ?? null,
                'extended_amount' => $extendedAmount,
                'frequency' => $this->normalizeText($mapped['frequency'] ?? null),
                'void_settlements' => ($mapped['void_settlement'] ?? null) !== null && $settlementIds !== [],
                'banking_update' => $mapped['banking_update'] ?? [],
                'creditor_change' => $mapped['creditor_change'] ?? [],
                'sponsor_update' => $mapped['sponsor_update'] ?? [],
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractRawPairs(string $rawText): array
    {
        $pairs = [];

        foreach (preg_split('/\r\n|\r|\n/', $rawText) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));

            if ($label === '') {
                continue;
            }

            $pairs[$label] = $value;
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getFirst(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $containerKeys
     * @param list<string> $flatKeys
     * @return array<string, mixed>
     */
    private function getStructuredPayload(array $payload, array $containerKeys, array $flatKeys): array
    {
        $structured = [];

        foreach ($containerKeys as $containerKey) {
            $candidate = $payload[$containerKey] ?? null;

            if (!is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $structured[$key] = $value;
                }
            }
        }

        foreach ($flatKeys as $flatKey) {
            if (!array_key_exists($flatKey, $payload)) {
                continue;
            }

            $value = $payload[$flatKey];
            if (is_scalar($value) || $value === null) {
                $structured[$flatKey] = $value;
            }
        }

        return $structured;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCustomerId(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/^(LLG|LDR|LTWR)-/i', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function normalizeCompany(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        return $normalized === null ? null : strtoupper($normalized);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value, bool $allowBareCommaSplit = false): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $items = $value;
        } else {
            $stringValue = (string) $value;

            if (str_contains($stringValue, ', ')) {
                $items = preg_split('/\s*,\s*/', $stringValue) ?: [];
            } elseif ($allowBareCommaSplit && str_contains($stringValue, ',')) {
                $items = preg_split('/\s*,\s*/', $stringValue) ?: [];
            } else {
                $items = [$stringValue];
            }
        }

        $normalized = [];

        foreach ($items as $item) {
            $text = $this->normalizeText($item);

            if ($text === null) {
                continue;
            }

            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * @param list<string> $values
     * @param array<string, array<int, string>> $errors
     * @return list<string>
     */
    private function normalizeSettlementIds(array $values, string $errorKey, array &$errors): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $settlementId = $this->normalizeSettlementId($value);

            if ($settlementId === null) {
                $errors[$errorKey][] = 'The settlement identifier must contain a numeric settlement identifier.';
                continue;
            }

            $normalized[] = $settlementId;
        }

        return $normalized;
    }

    private function normalizeSettlementId(mixed $value): ?string
    {
        $text = $this->normalizeText($value);
        if ($text === null) {
            return null;
        }

        $firstSegment = explode('-', $text, 2)[0];
        if (preg_match('/\d+/', $firstSegment, $matches)) {
            return $matches[0];
        }

        if (preg_match('/\d+/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param list<string> $values
     * @param array<string, array<int, string>> $errors
     * @return list<string>
     */
    private function normalizeAmountList(array $values, string $errorKey, array &$errors): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $amount = $this->normalizeAmount($value);

            if ($amount === null) {
                $errors[$errorKey][] = sprintf('The %s field must contain valid amounts.', $errorKey);
                continue;
            }

            $normalized[] = $amount;
        }

        return $normalized;
    }

    private function normalizeAmount(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace(['$', ',', ' '], '', $value);
        if ($clean === '') {
            return null;
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /**
     * @param list<string> $values
     * @param array<string, array<int, string>> $errors
     * @return list<string>
     */
    private function normalizeDateList(array $values, string $errorKey, array &$errors): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $date = $this->normalizeDate($value);

            if ($date === null) {
                $errors[$errorKey][] = sprintf('The %s field must contain dates in YYYY-MM-DD or MM/DD/YYYY format.', $errorKey);
                continue;
            }

            $normalized[] = $date;
        }

        return $normalized;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$year, $month, $day] = array_map('intval', explode('-', $value));
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            [$month, $day, $year] = array_map('intval', explode('/', $value));
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }
}
