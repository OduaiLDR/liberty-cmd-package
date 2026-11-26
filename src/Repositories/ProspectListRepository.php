<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Cmd\Reports\Services\ProspectListFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use SplTempFileObject;

class ProspectListRepository extends SqlSrvRepository
{
    private const EXPORT_BATCH_SIZE = 2000;

    public function count(array $filters, Carbon $asOf): int
    {
        return $this->withQueryTimeout(240, fn (): int => $this->filtered($filters, $asOf)->count());
    }

    public function eachBatch(array $filters, array $columns, Carbon $asOf, int $rowLimit, callable $consume): void
    {
        $this->withQueryTimeout(240, function () use ($filters, $columns, $asOf, $rowLimit, $consume): void {
            if (! empty($filters['fresh_mailed'])) {
                $this->eachFreshBatch($filters, $columns, $asOf, $rowLimit, $consume);

                return;
            }

            $query = $this->exportQuery($filters, $columns, $asOf);
            $lastRow = null;
            while ($rowLimit > 0) {
                $pageSize = min(self::EXPORT_BATCH_SIZE, $rowLimit);
                $page = (clone $query)->forPageAfterId($pageSize, $lastRow?->prospect_id, 'p.PK');
                $rows = $this->readExportPage($page, $filters, $columns);
                if ($rows->isEmpty() || $consume($rows) === false || $rows->count() < $pageSize) {
                    return;
                }

                $lastRow = $rows->last();
                $rowLimit -= $rows->count();
            }
        });
    }

    public function preview(array $filters, array $columns, Carbon $asOf, int $rowLimit): Collection
    {
        return $this->withQueryTimeout(8, function () use ($filters, $columns, $asOf, $rowLimit): Collection {
            $query = $this->exportQuery($filters, $columns, $asOf);
            $page = empty($filters['fresh_mailed'])
                ? $query->forPageAfterId($rowLimit, null, 'p.PK')
                : $this->rankByMailDate($query, $asOf, $rowLimit);

            return $this->readExportPage($page, $filters, $columns);
        });
    }

    private function eachFreshBatch(array $filters, array $columns, Carbon $asOf, int $rowLimit, callable $consume): void
    {
        if ($rowLimit <= 0) {
            return;
        }
        if ($rowLimit <= self::EXPORT_BATCH_SIZE) {
            $query = $this->rankByMailDate($this->exportQuery($filters, $columns, $asOf), $asOf, $rowLimit);
            $rows = $this->readExportPage($query, $filters, $columns);
            if ($rows->isNotEmpty()) {
                $consume($rows);
            }

            return;
        }

        $candidates = $this->filtered($filters, $asOf)->select('p.PK as prospect_id');
        $ranked = $this->rankByMailDate($candidates, $asOf, $rowLimit);
        // Close the SQL cursor before fetching CSV columns. Production connections disable MARS.
        $rankedIds = new SplTempFileObject();
        foreach ($ranked->cursor() as $row) {
            if ($rankedIds->fputcsv([$row->prospect_id, $row->mailed_on], escape: '') === false) {
                throw new RuntimeException('Unable to store the ranked prospect IDs.');
            }
        }
        $rankedIds->rewind();

        $eligibleRows = $this->exportQuery($filters, $columns, $asOf, matchMail: false);
        while (! $rankedIds->eof()) {
            $dates = [];
            while (count($dates) < self::EXPORT_BATCH_SIZE && ($entry = $rankedIds->fgetcsv(escape: '')) !== false && $entry[0] !== null) {
                $dates[(int) $entry[0]] = $entry[1];
            }
            if ($dates === []) {
                return;
            }

            // Recheck eligibility and suppression in case another workflow updated a prospect.
            $page = (clone $eligibleRows)->whereIntegerInRaw('p.PK', array_keys($dates));
            $rows = $this->readExportPage($page, [], $columns)->keyBy('prospect_id');
            $orderedRows = new Collection();
            foreach ($dates as $id => $mailedOn) {
                if ($row = $rows->get($id)) {
                    $row->mailed_on = $mailedOn;
                    $orderedRows->push($row);
                }
            }
            if ($orderedRows->isNotEmpty() && $consume($orderedRows) === false) {
                return;
            }
        }
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

    private function exportQuery(array $filters, array $columns, Carbon $asOf, bool $matchMail = true): Builder
    {
        $fields = ['p.PK as prospect_id'];
        if (array_intersect($columns, ['first_name', 'last_name']) !== []) {
            $fields[] = 'p.Client as name_fallback';
        }
        $needsTu = false;
        $definitions = ProspectListFields::columns();
        foreach ($columns as $column) {
            if ($column === 'personal_loan_amount') {
                $fields[] = 'p.Current_Debt_Source as loan_source';
                $fields[] = 'p.Current_Debt_Source_PK as loan_source_pk';
                continue;
            }
            $source = $definitions[$column][1];
            if ($source === null) {
                continue;
            }
            $fields[] = "$source as $column";
            $needsTu = $needsTu || str_starts_with($source, 't.');
        }

        return $this->filtered($filters, $asOf, $needsTu, $matchMail)->select($fields);
    }

    private function rankByMailDate(Builder $query, Carbon $asOf, int $rowLimit): Builder
    {
        $query->selectSub($this->mailingHistory($asOf)->selectRaw('MAX(d.Send_Date)'), 'mailed_on');

        return $this->connection()->query()->fromSub($query, 'ranked_prospects')
            ->orderByDesc('mailed_on')->orderBy('prospect_id')->limit($rowLimit);
    }

    private function readExportPage(Builder $query, array $filters, array $columns): Collection
    {
        $database = $query->getConnection();
        if ($database->getDriverName() === 'sqlsrv' && isset($filters['date_from'])) {
            // A small TOP can select a PK scan through older rows before reaching a recent date range.
            $sql = $query->toSql()." OPTION (USE HINT('DISABLE_OPTIMIZER_ROWGOAL'))";
            $rows = new Collection($database->select($sql, $query->getBindings()));
        } else {
            $rows = $query->get();
        }

        if (in_array('external_system_id', $columns, true)) {
            $mailerIds = $this->existingMailerIds($rows->pluck('prospect_id')->all());
            foreach ($rows as $row) {
                $row->external_system_id = isset($mailerIds[$row->prospect_id]) ? trim($mailerIds[$row->prospect_id]) : null;
            }
        }
        if (in_array('personal_loan_amount', $columns, true)) {
            // Resolve this projection after paging to avoid joining all qualifying prospects to TU.
            $sourceKeys = $rows->where('loan_source', 'TblTUData')->pluck('loan_source_pk')->filter()->unique()->all();
            $debtAmounts = $sourceKeys === [] ? [] : $this->table('TblTUData')->whereIntegerInRaw('PK', $sourceKeys)
                ->pluck(ProspectListFields::TU_DEBT_COLUMN, 'PK')->all();
            foreach ($rows as $row) {
                $row->personal_loan_amount = $row->loan_source === 'TblTUData' ? ($debtAmounts[$row->loan_source_pk] ?? null) : null;
            }
        }

        return $rows;
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

    private function filtered(array $filters, Carbon $asOf, bool $needsTu = false, bool $matchMail = true): Builder
    {
        $query = $this->table('TblProspects', 'p');

        $this->applyEligibility($query, $filters, $needsTu);
        $this->applyFilters($query, $filters, $asOf, $matchMail);

        return $query;
    }

    private function applyEligibility(Builder $query, array $filters, bool $needsTu = false): void
    {
        foreach (array_intersect_key(ProspectListFields::filterColumns(), $filters['fields'] ?? []) as $definition) {
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

    private function applyFilters(Builder $query, array $filters, Carbon $asOf, bool $matchMail = true): void
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
        if (! empty($filters['fresh_mailed'])) {
            $query->whereNull('p.Latest_Export_Date')->whereNull('p.Last_SMS_Date');
            if ($matchMail) {
                $query->whereExists($this->mailingHistory($asOf));
            }
        } elseif (isset($filters['months_since_pull'])) {
            $cutoff = $asOf->copy()->subMonthsNoOverflow((int) $filters['months_since_pull'])->toDateString();
            foreach (['Latest_Export_Date', 'Last_SMS_Date'] as $column) {
                $query->where(fn (Builder $dates) => $dates->whereNull("p.$column")->orWhere("p.$column", '<', $cutoff));
            }
        }

        $definitions = ProspectListFields::filterColumns();
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

    private function mailingHistory(Carbon $asOf): Builder
    {
        return $this->table('TblMailers', 'm')
            ->join('TblMarketing as d', 'd.Drop_Name', '=', 'm.Drop_Name')
            ->whereColumn('m.Client', 'p.Client')
            ->whereColumn('m.Address', 'p.Address')
            ->where('m.Client', '<>', '')
            ->where('m.Address', '<>', '')
            ->whereRaw('m.SMS_Zip5 = SUBSTRING(p.Zip, 1, 5)')
            ->where('d.Send_Date', '<=', $asOf->toDateString());
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
            'contact' => ['contact'], 'last_pull' => empty($filters['fresh_mailed']) ? ['months_since_pull'] : [],
            'fresh_mailed' => ['fresh_mailed'],
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
            if ($selection !== [] && ($group !== 'fresh_mailed' || ! empty($selection['fresh_mailed']))) {
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
                $definition = ProspectListFields::filterColumns()[$id];
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
