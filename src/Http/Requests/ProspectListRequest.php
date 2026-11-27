<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Requests;

use Cmd\Reports\Services\ProspectCampaignCsv;
use Cmd\Reports\Services\ProspectListFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProspectListRequest extends FormRequest
{
    public function rules(): array
    {
        $columnDefinitions = ProspectListFields::columns();
        $inspection = $this->routeIs('cmd.prospect-list.inspect');
        if ($inspection && ! $this->has('columns')) {
            foreach (ProspectCampaignCsv::COLUMNS as $field => $type) {
                $columnDefinitions[$field] ??= [$field, null, null, $type];
            }

            return $this->inspectionRules($columnDefinitions);
        }
        $filterDefinitions = ProspectListFields::filterColumns();
        $rules = [
            'id' => ['required', 'uuid'],
            'filters' => ['present', 'array:states,debt_min,debt_max,score_min,score_max,age_min,age_max,date_from,date_to,contact,months_since_pull,fresh_mailed,recent_first,max_record_age_weeks,suppression,suppression_weeks,include_opted_out,include_bad_phone,include_contacted,fields'],
            'filters.fields' => ['sometimes', 'array:'.implode(',', array_keys($filterDefinitions))],
            'filters.states' => ['sometimes', 'array', 'list', 'max:60'],
            'filters.states.*' => ['required', 'regex:/\A[A-Z]{2}\z/', 'distinct'],
            'filters.debt_min' => ['sometimes', 'numeric', 'between:0,100000000'],
            'filters.debt_max' => ['sometimes', 'numeric', 'between:0,100000000'],
            'filters.score_min' => ['sometimes', 'integer', 'between:0,999'],
            'filters.score_max' => ['sometimes', 'integer', 'between:0,999'],
            'filters.date_from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'filters.date_to' => ['sometimes', 'date_format:Y-m-d', 'before:9999-12-31'],
            'filters.contact' => ['sometimes', Rule::in(['phone', 'phone_email'])],
            'filters.months_since_pull' => ['sometimes', 'integer', 'between:1,120'],
            'filters.fresh_mailed' => ['sometimes', 'boolean'],
            'filters.recent_first' => ['sometimes', 'boolean'],
            'filters.max_record_age_weeks' => ['sometimes', 'integer', 'between:1,520'],
            'filters.suppression' => ['sometimes', Rule::in(['weeks', 'all_time'])],
            'filters.suppression_weeks' => ['required_if:filters.suppression,weeks', 'prohibited_unless:filters.suppression,weeks', 'integer', 'between:1,520'],
        ];

        foreach (['age_min', 'age_max'] as $key) {
            $rules["filters.$key"] = ProspectListFields::birthDateColumn() === null
                ? ['prohibited']
                : ['sometimes', 'integer', 'between:0,120'];
        }

        foreach (['include_opted_out', 'include_bad_phone', 'include_contacted'] as $key) {
            $rules["filters.$key"] = config('prospect-list-builder.allow_exclusion_overrides')
                ? ['sometimes', 'boolean']
                : ['prohibited'];
        }

        foreach (['debt', 'score', 'age'] as $field) {
            if ($this->has("filters.{$field}_min")) {
                $rules["filters.{$field}_max"][] = "gte:filters.{$field}_min";
            }
        }
        if ($this->has('filters.date_from')) {
            $rules['filters.date_to'][] = 'after_or_equal:filters.date_from';
        }

        foreach (array_intersect_key($filterDefinitions, (array) $this->input('filters.fields', [])) as $id => $definition) {
            $path = 'filters.fields.'.$id;
            $type = $definition[3];
            $operators = ['present', 'missing', $type === 'text' ? 'equals' : 'range'];
            $operator = $this->input($path.'.operator');
            $rules[$path] = ['required', 'array:operator,minimum,maximum,value'];
            $rules[$path.'.operator'] = ['required', Rule::in($operators)];
            $rules[$path.'.value'] = $operator === 'equals' ? ['required', 'string', 'max:255'] : ['prohibited'];
            foreach (['minimum' => 'maximum', 'maximum' => 'minimum'] as $bound => $other) {
                if ($operator !== 'range') {
                    $rules[$path.'.'.$bound] = ['prohibited'];
                    continue;
                }
                $rules[$path.'.'.$bound] = ['nullable', 'required_without:'.$path.'.'.$other];
                $rules[$path.'.'.$bound][] = $type === 'date' ? 'date_format:Y-m-d' : 'regex:/\A-?\d{1,18}(?:\.\d{1,10})?\z/';
            }
            if ($operator === 'range' && $this->filled($path.'.minimum') && $this->filled($path.'.maximum')) {
                if ($type === 'number') {
                    $rules[$path.'.minimum'][] = 'numeric';
                    $rules[$path.'.maximum'][] = 'numeric';
                }
                $rules[$path.'.maximum'][] = ($type === 'date' ? 'after_or_equal:' : 'gte:').$path.'.minimum';
            }
        }

        if ($inspection || $this->routeIs('cmd.prospect-list.exports')) {
            $allowedColumns = array_keys($columnDefinitions);
            $rules['columns'] = ['required', 'array', 'list', 'max:'.count($allowedColumns)];
            $rules['columns.*'] = ['required', 'string', 'distinct', Rule::in($allowedColumns)];
            if ($inspection) {
                unset($rules['id']);
                $rules['row_limit'] = ['sometimes', 'integer', 'min:1'];

                return [...$rules, ...$this->inspectionRules($columnDefinitions)];
            }
            $rules['count_id'] = ['required', 'uuid', 'different:id'];
            $rules['row_limit'] = ['required', 'integer', 'min:1'];
            $rules['rows_per_file'] = ['sometimes', 'nullable', 'integer', 'min:1', 'lte:row_limit'];
            $rules['columns'][0] = 'required_without:campaign';
            $rules['columns'][] = 'prohibits:campaign';
            $rules += ProspectCampaignCsv::rules();
        }

        return $rules;
    }

    private function inspectionRules(array $columnDefinitions): array
    {
        $field = $this->input('field');
        $type = is_string($field) ? ($columnDefinitions[$field][3] ?? 'text') : 'text';
        $operator = $this->input('filter.operator');
        $operators = ['all', 'present', 'missing', 'equals', $type === 'text' ? 'contains' : 'range'];
        $rules = [
            'field' => ['required', 'string', Rule::in(array_keys($columnDefinitions))],
            'filter' => ['required', 'array:operator,value,minimum,maximum'],
            'filter.operator' => ['required', Rule::in($operators)],
            'filter.value' => in_array($operator, ['equals', 'contains'], true) ? ['required', 'string', 'max:255'] : ['prohibited'],
            'filter.minimum' => ['prohibited'],
            'filter.maximum' => ['prohibited'],
        ];
        if ($operator === 'range' && $type !== 'text') {
            $format = $type === 'date' ? 'date_format:Y-m-d' : 'regex:/\A-?\d{1,18}(?:\.\d{1,10})?\z/';
            $rules['filter.minimum'] = ['nullable', 'required_without:filter.maximum', 'string', $format];
            $rules['filter.maximum'] = ['nullable', 'required_without:filter.minimum', 'string', $format];
            if ($this->filled('filter.minimum') && $this->filled('filter.maximum')) {
                if ($type === 'number') {
                    $rules['filter.minimum'][] = 'numeric';
                    $rules['filter.maximum'][] = 'numeric';
                }
                $rules['filter.maximum'][] = $type === 'date' ? 'after_or_equal:filter.minimum' : 'gte:filter.minimum';
            }
        }

        return $rules;
    }
}
