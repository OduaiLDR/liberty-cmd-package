<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ProspectListFields
{
    public const TU_DEBT_COLUMN = 'derivedFields1_unsecdebtbalance';

    private const EXPORT_COLUMNS = [
        'source' => ['Source', null, null, 'text', null],
        'external_system_id' => ['External ID', null, null, 'text', null],
        'campaign_id' => ['Campaign', null, null, 'text', null],
        'personal_loan_amount' => ['Personal Loan Amount', 't.'.self::TU_DEBT_COLUMN, null, 'number', null],
    ];

    /** @var array<string, array{string, string, string|null, string, string|null}> */
    private const COLUMNS = [
        'first_name' => ['First Name', 't.ncoaAddr_frstNme', 'NCOA_ADDR / FirstName', 'text', null],
        'last_name' => ['Last Name', 't.ncoaAddr_lastNme', 'NCOA_ADDR / LastName', 'text', null],
        'full_name' => ['Full Name', 'p.Client', null, 'text', null],
        'phone' => ['Phone', 'p.Phone', null, 'text', 'present'],
        'email' => ['Email', 'p.Email', null, 'text', 'present'],
        'address' => ['Address', 'p.Address', null, 'text', null],
        'city' => ['City', 'p.City', null, 'text', null],
        'state' => ['State', 'p.State', null, 'text', 'options'],
        'zip' => ['ZIP', 'p.Zip', null, 'text', null],
        'debt' => ['Debt', 'p.Current_Debt', null, 'number', 'range'],
        'tier' => ['Tier', 'p.Current_Tier', null, 'text', null],
        'date_added' => ['Date Added', 'p.Creation_Date', null, 'date', 'range'],
        'credit_as_of' => ['Credit As Of', 't.creditAsOfDate_creditAsOfDate', 'creditAsOfDate', 'date', null],
        'score' => ['Credit Score', 't.evtg04_finscore', 'EVTG04 / finscore', 'number', 'range'],
        'utilization' => ['Unsecured Utilization', 't.derivedFields_UnsecUtilization', 'Derived_Fields_1 / UnsecDebtUtilization', 'number', null],
    ];

    /** @return array<string, array{string, string|null, string|null, string, string|null}> */
    public static function columns(): array
    {
        static $columns;
        if ($columns !== null) {
            return $columns;
        }

        $columns = self::COLUMNS;
        $existingSources = array_flip(array_column($columns, 1));
        foreach (self::tuFields() as $column => [$label, $group, $output, , $type]) {
            if (! isset($existingSources['t.'.$column])) {
                $columns['tu_'.strtolower($column)] = [$label, 't.'.$column, "$group / $output", $type, null];
            }
        }

        return $columns += self::EXPORT_COLUMNS;
    }

    public static function filterColumns(): array
    {
        return array_diff_key(self::columns(), self::EXPORT_COLUMNS);
    }

    public static function csvHeader(string $field): string
    {
        return isset(self::EXPORT_COLUMNS[$field]) ? $field : self::columns()[$field][0];
    }

    /** @return array<string, array{string, string, string, string, string}> */
    private static function tuFields(): array
    {
        static $fields;

        return $fields ??= require __DIR__.'/../../config/prospect-list-tu-fields.php';
    }

    public static function birthDateColumn(): ?string
    {
        $column = config('prospect-list-builder.dob_column');

        return is_string($column) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column) === 1
            ? 't.'.$column
            : null;
    }

    public static function csvCell(object $row, string $field, string $type): mixed
    {
        if ($field === 'source') {
            return 'mailers';
        }
        if ($field === 'campaign_id') {
            return null;
        }
        $value = $row->{$field};
        if ($field === 'personal_loan_amount') {
            $debt = is_numeric($value) ? BigDecimal::of((string) $value) : null;

            return $debt?->isPositive() ? (string) $debt->dividedBy(1000, 0, RoundingMode::Ceiling)->multipliedBy(1000) : null;
        }
        if (in_array($field, ['first_name', 'last_name'], true) && trim((string) $value) === '') {
            $names = preg_split('/\s+/', trim((string) $row->name_fallback), 2);
            $value = $names[$field === 'first_name' ? 0 : 1] ?? '';
        }

        return $type === 'text' && is_string($value) ? self::escapeCsvText($value) : $value;
    }

    public static function escapeCsvText(string $value): string
    {
        return preg_match('/\A(?:[\t\r\n]|\s*[=+@-])/u', $value) === 1 ? "'".$value : $value;
    }

    public static function usPhoneDigits(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value, ' ');
        if (preg_match('/\A\+?[0-9(). -]+\z/', $value) !== 1) {
            return null;
        }
        $digits = str_replace(['+', '(', ')', '.', ' ', '-'], '', $value);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return strlen($digits) === 10 && ! str_starts_with($value, '+') ? $digits : null;
    }

    public static function catalog(): array
    {
        $columns = [];
        $tuFields = self::tuFields();
        foreach (self::columns() as $id => [$label, $column, $tuName, $type, $filter]) {
            if (isset(self::EXPORT_COLUMNS[$id])) {
                $columns[] = [
                    'id' => $id, 'label' => $label, 'column' => $column ?? $id, 'tuName' => null, 'type' => $type,
                    'filter' => null, 'group' => 'Campaign details', 'description' => null,
                    'csv_header' => $id, 'export_only' => true,
                ];
                continue;
            }
            $tuField = str_starts_with($column, 't.') ? $tuFields[substr($column, 2)] ?? null : null;
            $group = $tuField[1] ?? 'Prospect details';
            $description = $tuField[3] ?? null;
            if ($tuField !== null) {
                $tuName = "$group / {$tuField[2]}";
            }
            $columns[] = compact('id', 'label', 'column', 'tuName', 'type', 'filter', 'group', 'description');
        }

        return [
            'columns' => $columns,
            'campaign_columns' => ProspectCampaignCsv::catalog(),
            'age_filter' => self::birthDateColumn() !== null,
            'exclusion_overrides' => config('prospect-list-builder.allow_exclusion_overrides', false),
            'tcpa_scrub' => (bool) config('prospect-list-builder.tcpa_scrub.enabled'),
            'data_source' => config('prospect-list-builder.data_source'),
        ];
    }
}
