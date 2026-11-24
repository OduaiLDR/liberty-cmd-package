<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

final class ProspectListFields
{
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

    /** @return array<string, array{string, string, string|null, string, string|null}> */
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

        return $columns;
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
        $value = $row->{$field};
        if (in_array($field, ['first_name', 'last_name'], true) && trim((string) $value) === '') {
            $names = preg_split('/\s+/', trim((string) $row->name_fallback), 2);
            $value = $names[$field === 'first_name' ? 0 : 1] ?? '';
        }
        if ($type === 'text' && is_string($value) && preg_match('/\A(?:[\t\r\n]|\s*[=+@-])/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    public static function catalog(): array
    {
        $columns = [];
        $tuFields = self::tuFields();
        foreach (self::columns() as $id => [$label, $column, $tuName, $type, $filter]) {
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
            'age_filter' => self::birthDateColumn() !== null,
            'exclusion_overrides' => config('prospect-list-builder.allow_exclusion_overrides', false),
            'tcpa_scrub' => (bool) config('prospect-list-builder.tcpa_scrub.enabled'),
            'data_source' => config('prospect-list-builder.data_source'),
        ];
    }
}
