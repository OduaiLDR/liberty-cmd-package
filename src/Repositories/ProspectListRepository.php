<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Cmd\Reports\Services\ProspectListFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProspectListRepository extends SqlSrvRepository
{
    public function count(array $filters, Carbon $asOf): int
    {
        return $this->withQueryTimeout(240, fn (): int => $this->filtered($filters, $asOf)->count());
    }

    public function eachBatch(array $filters, array $columns, Carbon $asOf, int $rowLimit, callable $consume): void
    {
        $this->withQueryTimeout(240, fn (): bool => $this->exportQuery($filters, $columns, $asOf)
            ->limit($rowLimit)->chunkById(2000, $consume, 'p.PK', 'prospect_id'));
    }

    public function preview(array $filters, array $columns, Carbon $asOf, int $rowLimit): Collection
    {
        return $this->withQueryTimeout(8, fn (): Collection => $this->exportQuery($filters, $columns, $asOf)
            ->orderBy('p.PK')->limit($rowLimit)->get());
    }

    /**
     * @param list<int> $prospectIds
     * @return array<int, string>
     */
    public function existingMailerIds(array $prospectIds): array
    {
        if ($prospectIds === []) {
            return [];
        }

        $linked = $this->table('TblProspects', 'p')
            ->join('TblMailers as m', 'm.PK', '=', 'p.Current_Debt_Source_PK')
            ->whereIntegerInRaw('p.PK', $prospectIds)
            ->where('p.Current_Debt_Source', 'TblMailers')
            ->whereRaw("NULLIF(TRIM(m.External_ID), '') IS NOT NULL")
            ->pluck('m.External_ID', 'p.PK')->all();

        $unlinkedProspectIds = array_diff($prospectIds, array_keys($linked));
        if ($unlinkedProspectIds === []) {
            return $linked;
        }

        $externalId = 'TRIM(m.External_ID)';
        // IDs with different casing can identify separate leads at the recipient.
        $distinctId = $this->connection()->getDriverName() === 'sqlsrv' ? "CONVERT(varbinary(8000), $externalId)" : $externalId;
        $matched = $this->table('TblProspects', 'p')
            ->join('TblMailers as m', static function (JoinClause $join): void {
                $join->on('m.Client', '=', 'p.Client')->on('m.Address', '=', 'p.Address')
                    ->whereRaw('m.SMS_Zip5 = SUBSTRING(p.Zip, 1, 5)');
            })
            ->whereIntegerInRaw('p.PK', $unlinkedProspectIds)
            ->where('p.Client', '<>', '')
            ->where('p.Address', '<>', '')
            ->where('p.Zip', 'like', '_____%')
            ->whereRaw("NULLIF($externalId, '') IS NOT NULL")
            ->selectRaw("p.PK, MIN($externalId) AS external_id")
            ->groupBy('p.PK')
            ->havingRaw("COUNT(DISTINCT $distinctId) = 1")
            ->pluck('external_id', 'PK')->all();

        return $linked + $matched;
    }

    private function exportQuery(array $filters, array $columns, Carbon $asOf): Builder
    {
        $fields = ['p.PK as prospect_id'];
        if (array_intersect($columns, ['first_name', 'last_name']) !== []) {
            $fields[] = 'p.Client as name_fallback';
        }
        $needsTu = false;
        $definitions = ProspectListFields::columns();
        foreach ($columns as $column) {
            $source = $definitions[$column][1];
            $fields[] = "$source as $column";
            $needsTu = $needsTu || str_starts_with($source, 't.');
        }

        return $this->filtered($filters, $asOf, $needsTu)->select($fields);
    }

    /** Call after the private artifact is written and while holding the runner's prospect export lock. */
    public function stamp(iterable $prospectIdBatches, Carbon $asOf): void
    {
        $date = $asOf->toDateString();
        $this->connection()->transaction(function () use ($prospectIdBatches, $date): void {
            foreach ($prospectIdBatches as $ids) {
                foreach (['Latest_Export_Date', 'Last_SMS_Date'] as $column) {
                    $this->table('TblProspects')->whereIntegerInRaw('PK', $ids)
                        ->where(fn (Builder $dates) => $dates->whereNull($column)->orWhere($column, '<', $date))
                        ->update([$column => $date]);
                }
            }
        });
    }

    private function filtered(array $filters, Carbon $asOf, bool $needsTu = false): Builder
    {
        $query = $this->table('TblProspects', 'p');

        $this->applyEligibility($query, $filters, $needsTu);
        $this->applyFilters($query, $filters, $asOf);

        return $query;
    }

    private function applyEligibility(Builder $query, array $filters, bool $needsTu = false): void
    {
        foreach (array_intersect_key(ProspectListFields::columns(), $filters['fields'] ?? []) as $definition) {
            $needsTu = $needsTu || str_starts_with($definition[1], 't.');
        }
        if ($needsTu || array_intersect(['age_min', 'age_max', 'score_min', 'score_max'], array_keys($filters)) !== []) {
            $query->leftJoin('TblTUData as t', static function (JoinClause $join): void {
                $join->on('t.PK', '=', 'p.Current_Debt_Source_PK')->where('p.Current_Debt_Source', 'TblTUData');
            });
        }

        foreach (['include_opted_out' => 'Opt_Out', 'include_bad_phone' => 'Invalid_Phone', 'include_contacted' => 'Contacted'] as $flag => $column) {
            if (empty($filters[$flag])) {
                $query->where("p.$column", 0);
            }
        }
    }

    private function applyFilters(Builder $query, array $filters, Carbon $asOf): void
    {
        if (! empty($filters['states'])) {
            $query->whereIn('p.State', $filters['states']);
        }
        foreach (['debt' => 'p.Current_Debt', 'score' => 't.evtg04_finscore'] as $field => $column) {
            if (isset($filters[$field.'_min'])) {
                $query->where($column, '>=', $filters[$field.'_min']);
            }
            if (isset($filters[$field.'_max'])) {
                $query->where($column, '<=', $filters[$field.'_max']);
            }
        }
        if (isset($filters['date_from'])) {
            $query->where('p.Creation_Date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('p.Creation_Date', '<', Carbon::parse($filters['date_to'])->addDay()->toDateString());
        }
        $birthDate = $this->birthDateExpression($query);
        if ($birthDate !== null && (isset($filters['age_min']) || isset($filters['age_max']))) {
            $bounds = [
                $asOf->copy()->subYearsNoOverflow((int) ($filters['age_max'] ?? 120) + 1)->addDay()->toDateString(),
                $asOf->copy()->subYearsNoOverflow((int) ($filters['age_min'] ?? 0))->toDateString(),
            ];
            $query->whereRaw("$birthDate BETWEEN ? AND ?", $bounds);
        }
        if (isset($filters['contact'])) {
            $query->whereNotNull('p.Phone')->where('p.Phone', '<>', '');
            if ($filters['contact'] === 'phone_email') {
                $query->whereNotNull('p.Email')->where('p.Email', '<>', '');
            }
        }
        if (isset($filters['months_since_pull'])) {
            $cutoff = $asOf->copy()->subMonthsNoOverflow((int) $filters['months_since_pull'])->toDateString();
            foreach (['Latest_Export_Date', 'Last_SMS_Date'] as $column) {
                $query->where(fn (Builder $dates) => $dates->whereNull("p.$column")->orWhere("p.$column", '<', $cutoff));
            }
        }

        $definitions = ProspectListFields::columns();
        foreach ($filters['fields'] ?? [] as $id => $selection) {
            [, $column] = $definitions[$id];
            match ($selection['operator']) {
                'present' => $query->whereRaw('NOT '.$this->missingCondition($query, $definitions[$id])),
                'missing' => $query->whereRaw($this->missingCondition($query, $definitions[$id])),
                'equals' => $query->where($column, '=', $selection['value']),
                'range' => $query
                    ->when(isset($selection['minimum']), fn (Builder $range) => $range->where($column, '>=', $selection['minimum']))
                    ->when(isset($selection['maximum']), fn (Builder $range) => $range->where($column, '<=', $selection['maximum'])),
            };
        }
    }

    /** @param array{string, string, string|null, string, string|null} $definition */
    private function missingCondition(Builder $query, array $definition): string
    {
        $column = $query->getGrammar()->wrap($definition[1]);

        return $definition[3] === 'text' ? "($column IS NULL OR LTRIM(RTRIM($column)) = '')" : "($column IS NULL)";
    }

    /** @return array{sample_size: int, filters: list<array{key: string, label: string|null, matching: int, missing: int|null}>, states: array<string, int>} */
    public function diagnostics(array $filters, Carbon $asOf): array
    {
        $groups = [
            'states' => ['states'], 'debt' => ['debt_min', 'debt_max'], 'score' => ['score_min', 'score_max'],
            'age' => ['age_min', 'age_max'], 'date_added' => ['date_from', 'date_to'],
            'contact' => ['contact'], 'last_pull' => ['months_since_pull'],
        ];
        $sample = $this->table('TblProspects')->orderByDesc('PK')->limit(2500);
        $query = $this->connection()->query()->fromSub($sample, 'p')->select('p.State')
            ->selectRaw('COUNT(*) as sample_size')->groupBy('p.State');
        $this->applyEligibility($query, $filters);
        $combinedCriteria = $query->newQuery();
        $this->applyFilters($combinedCriteria, $filters, $asOf);
        $combinedCondition = substr($combinedCriteria->getGrammar()->compileWheres($combinedCriteria), strlen('where '));
        $query->selectRaw($combinedCondition === '' ? 'COUNT(*) as records' : "COUNT(CASE WHEN $combinedCondition THEN 1 END) as records",
            $combinedCriteria->getBindings());

        $selections = [];
        foreach ($groups as $group => $keys) {
            $selection = array_intersect_key($filters, array_flip($keys));
            if ($selection !== []) {
                $selections[$group] = $selection;
            }
        }
        foreach ($filters['fields'] ?? [] as $id => $selection) {
            $selections['field_'.$id] = ['fields' => [$id => $selection]];
        }

        $active = [];
        foreach ($selections as $group => $selection) {
            $criterion = $query->newQuery();
            $this->applyFilters($criterion, $selection, $asOf);
            $condition = substr($criterion->getGrammar()->compileWheres($criterion), strlen('where '));
            $query->selectRaw("COUNT(CASE WHEN $condition THEN 1 END) as [{$group}_matching]", $criterion->getBindings());

            $missing = match ($group) {
                'states' => "p.State IS NULL OR p.State = ''",
                'debt' => 'p.Current_Debt IS NULL',
                'score' => 't.evtg04_finscore IS NULL',
                'age' => $this->birthDateExpression($query).' IS NULL',
                'date_added' => 'p.Creation_Date IS NULL',
                'contact' => ($selection['contact'] === 'phone_email')
                    ? "p.Phone IS NULL OR p.Phone = '' OR p.Email IS NULL OR p.Email = ''"
                    : "p.Phone IS NULL OR p.Phone = ''",
                default => null,
            };
            $label = null;
            if (isset($selection['fields'])) {
                $id = array_key_first($selection['fields']);
                $definition = ProspectListFields::columns()[$id];
                $operator = $selection['fields'][$id]['operator'];
                $label = $definition[0].match ($operator) {
                    'present' => ' · has data', 'missing' => ' · is missing', 'range' => ' · in range', 'equals' => ' · exact value',
                };
                $missing = $operator === 'missing' ? null : $this->missingCondition($query, $definition);
            }
            if ($missing !== null) {
                $query->selectRaw("COUNT(CASE WHEN $missing THEN 1 END) as [{$group}_missing]");
            }
            $active[$group] = ['label' => $label, 'has_missing' => $missing !== null];
        }

        $results = $this->withQueryTimeout(10, fn () => $query->get());
        $states = [];
        foreach ($results as $row) {
            $state = strtoupper(trim((string) $row->State));
            if ((int) $row->records > 0 && preg_match('/\A[A-Z]{2}\z/', $state) === 1) {
                $states[$state] = ($states[$state] ?? 0) + (int) $row->records;
            }
        }
        arsort($states);

        return [
            'sample_size' => (int) $results->sum('sample_size'),
            'filters' => array_map(static fn (string $group, array $details): array => [
                'key' => $group,
                'label' => $details['label'],
                'matching' => (int) $results->sum($group.'_matching'),
                'missing' => $details['has_missing'] ? (int) $results->sum($group.'_missing') : null,
            ], array_keys($active), array_values($active)),
            'states' => $states,
        ];
    }

    private function birthDateExpression(Builder $query): ?string
    {
        $birthDate = ProspectListFields::birthDateColumn();
        if ($birthDate === null) {
            return null;
        }

        $column = $query->getGrammar()->wrap($birthDate);

        // Azure stores TU birth dates as ISO text. Invalid or missing values cannot match.
        return $query->getConnection()->getDriverName() === 'sqlsrv'
            ? "TRY_CONVERT(date, NULLIF(LTRIM(RTRIM($column)), ''), 23)"
            : $column;
    }
}
