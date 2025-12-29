<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegalReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->resolvedDefinition()['labels'];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        $def = $this->resolvedDefinition();

        $query = $this->table($def['debt_table'], 'd')->select($def['selects']);

        if ($def['contact_table'] && $def['join_left'] && $def['join_right']) {
            $query->leftJoin($def['contact_table'] . ' as c', $def['join_left'], '=', $def['join_right']);
        }

        if ($def['plan_table'] && $def['plan_join_left'] && $def['plan_join_right']) {
            $query->leftJoin($def['plan_table'] . ' as ep', $def['plan_join_left'], '=', $def['plan_join_right']);
        }

        if ($def['defaults_table'] && $def['defaults_join_left'] && $def['defaults_join_right']) {
            $query->leftJoin($def['defaults_table'] . ' as ed', $def['defaults_join_left'], '=', $def['defaults_join_right']);
        }

        if ($def['creditor_table'] && $def['creditor_join_left'] && $def['creditor_join_right']) {
            $query->leftJoin($def['creditor_table'] . ' as cr1', $def['creditor_join_left'], '=', $def['creditor_join_right']);
        }

        if ($def['debt_buyer_table'] && $def['debt_buyer_join_left'] && $def['debt_buyer_join_right']) {
            $query->leftJoin($def['debt_buyer_table'] . ' as cr2', $def['debt_buyer_join_left'], '=', $def['debt_buyer_join_right']);
        }

        $this->applyFilters($query, $filters, $def['filter_columns'], $def);

        if ($def['order_by'] && isset($def['filter_columns'][$def['order_by']])) {
            $query->orderByRaw($def['filter_columns'][$def['order_by']] . ' desc');
        }

        return $query;
    }

    /**
     * @return array{
     *  debt_table:string,
     *  contact_table:?string,
     *  plan_table:?string,
     *  defaults_table:?string,
     *  creditor_table:?string,
     *  debt_buyer_table:?string,
     *  select:array<int,\Illuminate\Database\Query\Expression|string>,
     *  selects:array<int,\Illuminate\Database\Query\Expression|string>,
     *  labels:array<string,string>,
     *  filter_columns:array<string,string>,
     *  join_left:?string,
     *  join_right:?string,
     *  plan_join_left:?string,
     *  plan_join_right:?string,
     *  defaults_join_left:?string,
     *  defaults_join_right:?string,
     *  creditor_join_left:?string,
     *  creditor_join_right:?string,
     *  debt_buyer_join_left:?string,
     *  debt_buyer_join_right:?string,
     *  order_by:?string,
     *  balance_table:?string,
     *  balance_contact_col:?string,
     *  balance_current_col:?string,
     *  balance_stamp_col:?string,
     *  notes_table:?string,
     *  notes_debt_col:?string,
     *  notes_note_col:?string,
     *  notes_created_col:?string
     * }
     */
    protected function resolvedDefinition(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // Hardcoded primary tables to avoid expensive discovery
        $contactTable = $this->firstExistingTable(['dbo.TblContacts', 'TblContacts', 'CONTACTS']);
        $debtTable = $this->firstExistingTable(['dbo.TblDebts', 'TblDebts', 'DEBTS', 'dbo.TblEnrollment', 'TblEnrollment']);
        $planTable = $this->firstExistingTable(['dbo.Enrollment_Plan', 'Enrollment_Plan', 'ENROLLMENT_PLAN']);
        $defaultsTable = $this->firstExistingTable(['dbo.Enrollment_Defaults2', 'Enrollment_Defaults2', 'ENROLLMENT_DEFAULTS2']);
        $creditorTable = $this->firstExistingTable(['dbo.Creditors', 'Creditors', 'CREDITORS', 'dbo.TblCreditors', 'TblCreditors']);
        $balanceTable = $this->firstExistingTable(['dbo.Contact_Balances', 'Contact_Balances', 'CONTACT_BALANCES']);
        $notesTable = $this->firstExistingTable(['dbo.Debt_Notes', 'Debt_Notes', 'DEBT_NOTES']);
        $customTable = $this->firstExistingTable(['dbo.CustomField_Data', 'CustomField_Data', 'CUSTOMFIELD_DATA']);

        $contactColumns = $contactTable ? $this->listColumns($contactTable) : [];
        $debtColumns = $debtTable ? $this->listColumns($debtTable) : [];
        $planColumns = $planTable ? $this->listColumns($planTable) : [];
        $defaultsColumns = $defaultsTable ? $this->listColumns($defaultsTable) : [];
        $creditorColumns = $creditorTable ? $this->listColumns($creditorTable) : [];
        $balanceColumns = $balanceTable ? $this->listColumns($balanceTable) : [];
        $notesColumns = $notesTable ? $this->listColumns($notesTable) : [];
        $customColumns = $customTable ? $this->listColumns($customTable) : [];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        $cIdCol = null;
        $dContactCol = null;
        $dIdCol = null;
        $planIdCol = null;
        $defaultsIdCol = null;
        $creditorIdCol = null;

        $mapC = [
            'ID' => ['label' => 'Contact ID', 'candidates' => ['ID', 'CONTACT_ID']],
            'FIRSTNAME' => ['label' => 'First Name', 'candidates' => ['FIRSTNAME', 'FIRST_NAME']],
            'LASTNAME' => ['label' => 'Last Name', 'candidates' => ['LASTNAME', 'LAST_NAME']],
            'DOB' => ['label' => 'DOB', 'candidates' => ['DOB', 'DATE_OF_BIRTH']],
            'STATE' => ['label' => 'State', 'candidates' => ['STATE', 'STATE_CODE']],
            'ENROLLED' => ['label' => 'Enrolled', 'candidates' => ['ENROLLED', 'IS_ENROLLED']],
        ];

        foreach ($mapC as $alias => $def) {
            $col = $this->firstMatchingColumn($contactColumns, $def['candidates']);
            if (!$col) {
                continue;
            }
            $col = $this->sanitizeColumnName($col);
            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('c.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 'c.[' . $col . ']';
            if ($alias === 'ID') {
                $cIdCol = $col;
            }
        }

        $mapD = [
            'D_ID' => ['label' => 'Debt ID', 'candidates' => ['ID', 'DEBT_ID']],
            'CONTACT_ID' => ['label' => 'Contact ID (Debt)', 'candidates' => ['CONTACT_ID', 'CID', 'ID']],
            'SUMMONS_DATE' => ['label' => 'Summons Date', 'candidates' => ['SUMMONS_DATE', 'SUMMONS']],
            'ANSWER_DATE' => ['label' => 'Answer Date', 'candidates' => ['ANSWER_DATE', 'ANSWER']],
            'ACCOUNT_NUM' => ['label' => 'Account Num', 'candidates' => ['ACCOUNT_NUM', 'ACCOUNT_NUMBER']],
            'VERIFIED_AMOUNT' => ['label' => 'Verified Amount', 'candidates' => ['VERIFIED_AMOUNT', 'AMOUNT', 'BALANCE']],
            'POA_SENT_DATE' => ['label' => 'POA Sent Date', 'candidates' => ['POA_SENT_DATE', 'POA_SENT']],
            'SETTLEMENT_DATE' => ['label' => 'Settlement Date', 'candidates' => ['SETTLEMENT_DATE', 'SETTLED_DATE']],
            'D_ENROLLED' => ['label' => 'Debt Enrolled', 'candidates' => ['ENROLLED', 'IS_ENROLLED']],
            'HAS_SUMMONS' => ['label' => 'Has Summons', 'candidates' => ['HAS_SUMMONS', 'SUMMONS_FLAG']],
            'SETTLED' => ['label' => 'Settled', 'candidates' => ['SETTLED', 'IS_SETTLED']],
            'CREDITOR_ID' => ['label' => 'Creditor ID', 'candidates' => ['CREDITOR_ID', 'CR_ID']],
            'DEBT_BUYER' => ['label' => 'Debt Buyer', 'candidates' => ['DEBT_BUYER', 'BUYER']],
            'LEGAL_NEGOTIATOR' => ['label' => 'Legal Negotiator', 'candidates' => ['LEGAL_NEGOTIATOR', 'NEGOTIATOR']],
            'ATTORNEY_ASSIGNMENT' => ['label' => 'Attorney Assignment', 'candidates' => ['ATTORNEY_ASSIGNMENT', 'ATTORNEY']],
            'LEGAL_CLAIM_ID' => ['label' => 'Legal Claim ID', 'candidates' => ['LEGAL_CLAIM_ID', 'CLAIM_ID']],
        ];

        foreach ($mapD as $alias => $def) {
            $col = $this->firstMatchingColumn($debtColumns, $def['candidates']);
            if (!$col) {
                continue;
            }
            $col = $this->sanitizeColumnName($col);
            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('d.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 'd.[' . $col . ']';
            if ($alias === 'CONTACT_ID') {
                $dContactCol = $col;
            }
            if ($alias === 'D_ID') {
                $dIdCol = $col;
            }
            if ($alias === 'CREDITOR_ID') {
                $creditorIdCol = $col;
            }
        }

        // Plan / Defaults
        $planContactCol = $this->firstMatchingColumn($planColumns, ['CONTACT_ID', 'LLG_ID', 'CID']);
        $planPlanCol = $this->firstMatchingColumn($planColumns, ['PLAN_ID', 'ID']);
        if ($planPlanCol) {
            $planIdCol = $this->sanitizeColumnName($planPlanCol);
        }
        if ($planIdCol) {
            $labels['PLAN_ID'] = 'Plan ID';
            $selects[] = DB::raw('ep.[' . $planIdCol . '] as [PLAN_ID]');
            $filterColumns['PLAN_ID'] = 'ep.[' . $planIdCol . ']';
        }

        $defaultsId = $this->firstMatchingColumn($defaultsColumns, ['ID', 'PLAN_ID']);
        $defaultsTitle = $this->firstMatchingColumn($defaultsColumns, ['TITLE', 'NAME']);
        if ($defaultsTitle) {
            $defaultsIdCol = $defaultsId ? $this->sanitizeColumnName($defaultsId) : null;
            $defaultsTitleCol = $this->sanitizeColumnName($defaultsTitle);
            $labels['TITLE'] = 'Title';
            $selects[] = DB::raw('ed.[' . $defaultsTitleCol . '] as [TITLE]');
            $filterColumns['TITLE'] = 'ed.[' . $defaultsTitleCol . ']';
        }

        // Creditors
        $creditorCompanyCol = $this->firstMatchingColumn($creditorColumns, ['COMPANY', 'NAME']);
        if ($creditorCompanyCol && $creditorIdCol) {
            $creditorCompanyCol = $this->sanitizeColumnName($creditorCompanyCol);
            $labels['CR_COMPANY'] = 'Creditor Company';
            $selects[] = DB::raw('cr1.[' . $creditorCompanyCol . '] as [CR_COMPANY]');
            $filterColumns['CR_COMPANY'] = 'cr1.[' . $creditorCompanyCol . ']';
        }

        // Debt buyer as creditor table reuse
        if ($creditorCompanyCol && $this->firstMatchingColumn($debtColumns, ['DEBT_BUYER', 'BUYER'])) {
            $labels['DEBT_BUYER_NAME'] = 'Debt Buyer Name';
            $selects[] = DB::raw('cr2.[' . $creditorCompanyCol . '] as [DEBT_BUYER_NAME]');
            $filterColumns['DEBT_BUYER_NAME'] = 'cr2.[' . $creditorCompanyCol . ']';
        }

        // Derived from balances
        $balanceContactCol = $this->firstMatchingColumn($balanceColumns, ['CONTACT_ID', 'LLG_ID', 'CID']);
        $balanceCurrentCol = $this->firstMatchingColumn($balanceColumns, ['CURRENT', 'BALANCE', 'SPA_BALANCE']);
        $balanceStampCol = $this->firstMatchingColumn($balanceColumns, ['STAMP', 'UPDATED_AT', 'CREATED_AT']);
        if ($balanceTable && $balanceContactCol && $balanceCurrentCol) {
            $bc = $this->sanitizeColumnName($balanceContactCol);
            $bal = $this->sanitizeColumnName($balanceCurrentCol);
            $bstamp = $balanceStampCol ? $this->sanitizeColumnName($balanceStampCol) : null;
            $order = $bstamp ? ' ORDER BY [' . $bstamp . '] DESC' : '';
            $labels['SPA_BALANCE'] = 'SPA Balance';
            $selects[] = DB::raw('(SELECT TOP 1 [' . $bal . '] FROM ' . $balanceTable . ' WHERE [' . $bc . ']=c.[' . ($cIdCol ?? $bc) . ']' . $order . ') as [SPA_BALANCE]');
            $filterColumns['SPA_BALANCE'] = '[SPA_BALANCE]';
        }

        // Derived from notes
        $notesDebtCol = $this->firstMatchingColumn($notesColumns, ['DEBT_ID', 'D_ID', 'ID']);
        $notesNoteCol = $this->firstMatchingColumn($notesColumns, ['NOTES', 'NOTE', 'TEXT']);
        $notesCreatedCol = $this->firstMatchingColumn($notesColumns, ['CREATED_AT', 'CREATED', 'DATE']);
        if ($notesTable && $notesDebtCol && $notesNoteCol && $notesCreatedCol && $dIdCol) {
            $ndc = $this->sanitizeColumnName($notesDebtCol);
            $nnc = $this->sanitizeColumnName($notesNoteCol);
            $ncc = $this->sanitizeColumnName($notesCreatedCol);
            $labels['NEGOTIATOR_LAST_NOTE'] = 'Negotiator Last Note';
            $selects[] = DB::raw('(SELECT TOP 1 [' . $nnc . '] FROM ' . $notesTable . ' WHERE [' . $ndc . ']=d.[' . $dIdCol . '] ORDER BY [' . $ncc . '] DESC) as [NEGOTIATOR_LAST_NOTE]');
            $labels['LATEST_NOTE_DATE'] = 'Latest Note Date';
            $selects[] = DB::raw('(SELECT TOP 1 [' . $ncc . '] FROM ' . $notesTable . ' WHERE [' . $ndc . ']=d.[' . $dIdCol . '] ORDER BY [' . $ncc . '] DESC) as [LATEST_NOTE_DATE]');
            $labels['PRIOR_NOTE_DATE'] = 'Prior Note Date';
            $selects[] = DB::raw('(SELECT [' . $ncc . '] FROM (SELECT [' . $ncc . '], ROW_NUMBER() OVER (PARTITION BY [' . $ndc . '] ORDER BY [' . $ncc . '] DESC) rn FROM ' . $notesTable . ' WHERE [' . $ndc . ']=d.[' . $dIdCol . ']) x WHERE rn = 2)');
            $filterColumns['NEGOTIATOR_LAST_NOTE'] = '[NEGOTIATOR_LAST_NOTE]';
            $filterColumns['LATEST_NOTE_DATE'] = '[LATEST_NOTE_DATE]';
            $filterColumns['PRIOR_NOTE_DATE'] = '[PRIOR_NOTE_DATE]';
        }

        // custom fields as generic N1/N2 if possible
        $customObjCol = $this->firstMatchingColumn($customColumns, ['OBJ_ID', 'OBJECT_ID', 'DEBT_ID']);
        $customStringCol = $this->firstMatchingColumn($customColumns, ['F_STRING', 'VALUE', 'TEXT']);
        $customIdCol = $this->firstMatchingColumn($customColumns, ['CUSTOM_ID', 'ID']);
        if ($customTable && $customObjCol && $customStringCol && $dIdCol) {
            $co = $this->sanitizeColumnName($customObjCol);
            $cs = $this->sanitizeColumnName($customStringCol);
            $cid = $customIdCol ? $this->sanitizeColumnName($customIdCol) : null;
            $orderCustom = $cid ? ' ORDER BY [' . $cid . '] DESC' : '';

            $labels['N1'] = 'N1';
            $selects[] = DB::raw('(SELECT TOP 1 [' . $cs . '] FROM ' . $customTable . ' WHERE [' . $co . ']=d.[' . $dIdCol . ']' . $orderCustom . ') as [N1]');
            $filterColumns['N1'] = '[N1]';

            $labels['N2'] = 'N2';
            $selects[] = DB::raw('(SELECT TOP 1 [' . $cs . '] FROM ' . $customTable . ' WHERE [' . $co . ']=d.[' . $dIdCol . ']' . ($cid ? ' ORDER BY [' . $cid . '] ASC' : '') . ') as [N2]');
            $filterColumns['N2'] = '[N2]';
        }

        // join detection between debt and contact
        // prefer explicit CONTACT_ID -> contact.ID join first
        $joinLeft = null;
        $joinRight = null;
        $candidateJoins = ['CONTACT_ID', 'ID', 'CID'];
        $dLookup = [];
        foreach ($debtColumns as $col) {
            $clean = $this->sanitizeColumnName($col);
            $dLookup[strtolower($clean)] = $clean;
        }
        $cLookup = [];
        foreach ($contactColumns as $col) {
            $clean = $this->sanitizeColumnName($col);
            $cLookup[strtolower($clean)] = $clean;
        }
        if ($contactTable && $debtTable && isset($dLookup['contact_id']) && $cIdCol) {
            $joinLeft = 'c.[' . $cIdCol . ']';
            $joinRight = 'd.[' . $dLookup['contact_id'] . ']';
        } else {
            foreach ($candidateJoins as $cand) {
                $lc = strtolower($cand);
                if (isset($dLookup[$lc]) && isset($cLookup[$lc])) {
                    $joinLeft = 'c.[' . $cLookup[$lc] . ']';
                    $joinRight = 'd.[' . $dLookup[$lc] . ']';
                    break;
                }
            }
        }

        $joinPossible = $contactTable && $joinLeft && $joinRight;
        if (!$joinPossible) {
            // rebuild selects/labels/filters to only include debt-side and derived fields that don't rely on contact/plan/default/creditor joins
            $labels = array_filter(
                $labels,
                fn($k) => !in_array($k, ['ID', 'FIRSTNAME', 'LASTNAME', 'DOB', 'STATE', 'ENROLLED', 'PLAN_ID', 'TITLE', 'CR_COMPANY', 'DEBT_BUYER_NAME', 'SPA_BALANCE'], true),
                ARRAY_FILTER_USE_KEY
            );
            $selects = array_values(array_filter($selects, function ($expr) {
                if ($expr instanceof \Illuminate\Database\Query\Expression) {
                    $value = $expr->getValue(DB::connection($this->connection)->getQueryGrammar());
                } else {
                    $value = (string) $expr;
                }
                return !str_contains($value, 'c.') && !str_starts_with($value, 'ep.') && !str_starts_with($value, 'ed.') && !str_contains($value, 'cr1.') && !str_contains($value, 'cr2.') && !str_contains($value, 'SPA_BALANCE');
            }));
            $filterColumns = array_filter($filterColumns, function ($v, $k) {
                return !str_contains($v, 'c.') && !str_starts_with($v, 'ep.') && !str_starts_with($v, 'ed.') && !str_contains($v, 'cr1.') && !str_contains($v, 'cr2.') && $k !== 'SPA_BALANCE';
            }, ARRAY_FILTER_USE_BOTH);
        }

        // Plan join only if contact join and contact id available
        $planJoinLeft = null;
        $planJoinRight = null;
        if ($planTable && $planContactCol && $joinPossible && $joinLeft && $joinRight) {
            $planJoinLeft = 'ep.[' . $this->sanitizeColumnName($planContactCol) . ']';
            // use the contact side of the established join
            $planJoinRight = $joinLeft;
        }

        $defaultsJoinLeft = null;
        $defaultsJoinRight = null;
        if ($defaultsTable && $planIdCol && $defaultsIdCol) {
            $defaultsJoinLeft = 'ed.[' . $defaultsIdCol . ']';
            $defaultsJoinRight = 'ep.[' . $planIdCol . ']';
        }

        $creditorJoinLeft = null;
        $creditorJoinRight = null;
        if ($creditorTable && $creditorIdCol) {
            $creditorJoinLeft = 'cr1.[ID]';
            $creditorJoinRight = 'd.[' . $creditorIdCol . ']';
        }

        $debtBuyerJoinLeft = null;
        $debtBuyerJoinRight = null;
        $debtBuyerCol = $this->firstMatchingColumn($debtColumns, ['DEBT_BUYER', 'BUYER']);
        if ($creditorTable && $debtBuyerCol) {
            $debtBuyerJoinLeft = 'cr2.[ID]';
            $debtBuyerJoinRight = 'd.[' . $this->sanitizeColumnName($debtBuyerCol) . ']';
        }

        $orderBy = array_key_exists('SUMMONS_DATE', $filterColumns) ? 'SUMMONS_DATE' : (array_key_first($filterColumns) ?: null);

        $this->resolved = [
            'debt_table' => $debtTable,
            'contact_table' => $contactTable,
            'plan_table' => $planTable,
            'defaults_table' => $defaultsTable,
            'creditor_table' => $creditorTable,
            'debt_buyer_table' => $creditorTable,
            'select' => $selects,
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'join_left' => $joinPossible ? $joinLeft : null,
            'join_right' => $joinPossible ? $joinRight : null,
            'plan_join_left' => $planJoinLeft,
            'plan_join_right' => $planJoinRight,
            'defaults_join_left' => $defaultsJoinLeft,
            'defaults_join_right' => $defaultsJoinRight,
            'creditor_join_left' => $creditorJoinLeft,
            'creditor_join_right' => $creditorJoinRight,
            'debt_buyer_join_left' => $debtBuyerJoinLeft,
            'debt_buyer_join_right' => $debtBuyerJoinRight,
            'order_by' => $orderBy,
            'balance_table' => $balanceTable,
            'balance_contact_col' => $balanceContactCol ? $this->sanitizeColumnName($balanceContactCol) : null,
            'balance_current_col' => $balanceCurrentCol ? $this->sanitizeColumnName($balanceCurrentCol) : null,
            'balance_stamp_col' => $balanceStampCol ? $this->sanitizeColumnName($balanceStampCol) : null,
            'notes_table' => $notesTable,
            'notes_debt_col' => $notesDebtCol ? $this->sanitizeColumnName($notesDebtCol) : null,
            'notes_note_col' => $notesNoteCol ? $this->sanitizeColumnName($notesNoteCol) : null,
            'notes_created_col' => $notesCreatedCol ? $this->sanitizeColumnName($notesCreatedCol) : null,
            'd_id_col' => $dIdCol,
        ];

        return $this->resolved;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     * @param  array<string, mixed>  $def
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns, array $def): void
    {
        $contains = static fn($value): bool => $value !== null && $value !== '';

        $map = $this->filterMap();

        foreach ($map as $input => $cfg) {
            $value = $filters[$input] ?? null;
            $alias = $cfg['alias'];
            if (!$contains($value) || !isset($filterColumns[$alias])) {
                continue;
            }

            $col = $filterColumns[$alias];
            switch ($cfg['type']) {
                case 'date_from':
                    $query->whereRaw('CAST(' . $col . ' AS date) >= ?', [$value]);
                    break;
                case 'date_to':
                    $query->whereRaw('CAST(' . $col . ' AS date) <= ?', [$value]);
                    break;
                case 'num_min':
                    $query->whereRaw($col . ' >= ?', [$value]);
                    break;
                case 'num_max':
                    $query->whereRaw($col . ' <= ?', [$value]);
                    break;
                default:
                    $query->whereRaw($col . ' like ?', ['%' . trim((string) $value) . '%']);
                    break;
            }
        }

        // has_notes filter if notes table available
        if (($filters['has_notes'] ?? null) !== null && $def['notes_table'] && $def['notes_debt_col'] && $def['notes_note_col'] && isset($filterColumns['D_ID'])) {
            if (in_array(strtolower((string) $filters['has_notes']), ['1', 'yes', 'y', 'true'], true)) {
                $query->whereExists(function ($sub) use ($def) {
                    $sub->selectRaw('1')
                        ->from($def['notes_table'])
                        ->whereColumn($def['notes_table'] . '.[' . $def['notes_debt_col'] . ']', '=', 'd.[' . ($def['d_id_col'] ?? $def['notes_debt_col']) . ']');
                });
            } elseif (in_array(strtolower((string) $filters['has_notes']), ['0', 'no', 'n', 'false'], true)) {
                $query->whereNotExists(function ($sub) use ($def) {
                    $sub->selectRaw('1')
                        ->from($def['notes_table'])
                        ->whereColumn($def['notes_table'] . '.[' . $def['notes_debt_col'] . ']', '=', 'd.[' . ($def['d_id_col'] ?? $def['notes_debt_col']) . ']');
                });
            }
        }
    }

    /**
     * @return array<string,array{alias:string,type:string}>
     */
    protected function filterMap(): array
    {
        return [
            'contact_id' => ['alias' => 'CONTACT_ID', 'type' => 'like'],
            'first_name' => ['alias' => 'FIRSTNAME', 'type' => 'like'],
            'last_name' => ['alias' => 'LASTNAME', 'type' => 'like'],
            'state' => ['alias' => 'STATE', 'type' => 'like'],
            'enrolled' => ['alias' => 'ENROLLED', 'type' => 'like'],
            'has_summons' => ['alias' => 'HAS_SUMMONS', 'type' => 'like'],
            'settled' => ['alias' => 'SETTLED', 'type' => 'like'],
            'debt_buyer' => ['alias' => 'DEBT_BUYER', 'type' => 'like'],
            'creditor_id' => ['alias' => 'CREDITOR_ID', 'type' => 'like'],
            'plan_id' => ['alias' => 'PLAN_ID', 'type' => 'like'],
            'summons_from' => ['alias' => 'SUMMONS_DATE', 'type' => 'date_from'],
            'summons_to' => ['alias' => 'SUMMONS_DATE', 'type' => 'date_to'],
            'answer_from' => ['alias' => 'ANSWER_DATE', 'type' => 'date_from'],
            'answer_to' => ['alias' => 'ANSWER_DATE', 'type' => 'date_to'],
            'poa_from' => ['alias' => 'POA_SENT_DATE', 'type' => 'date_from'],
            'poa_to' => ['alias' => 'POA_SENT_DATE', 'type' => 'date_to'],
            'settlement_from' => ['alias' => 'SETTLEMENT_DATE', 'type' => 'date_from'],
            'settlement_to' => ['alias' => 'SETTLEMENT_DATE', 'type' => 'date_to'],
            'dob_from' => ['alias' => 'DOB', 'type' => 'date_from'],
            'dob_to' => ['alias' => 'DOB', 'type' => 'date_to'],
            'verified_min' => ['alias' => 'VERIFIED_AMOUNT', 'type' => 'num_min'],
            'verified_max' => ['alias' => 'VERIFIED_AMOUNT', 'type' => 'num_max'],
            'legal_negotiator' => ['alias' => 'LEGAL_NEGOTIATOR', 'type' => 'like'],
        ];
    }

    /**
     * Expose input keys that are actually filterable based on detected columns.
     *
     * @return array<int,string>
     */
    public function allowedFilterInputs(): array
    {
        $def = $this->resolvedDefinition();
        $map = $this->filterMap();
        $allowed = [];
        foreach ($map as $input => $cfg) {
            if (isset($def['filter_columns'][$cfg['alias']])) {
                $allowed[] = $input;
            }
        }
        return $allowed;
    }

    /**
     * @return array{string, array<int,string>}
     */
    protected function detectContactTable(): array
    {
        foreach (['dbo.TblContacts', 'TblContacts', 'Contacts'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }

        $detected = $this->detectBestTable(["COLUMN_NAME LIKE '%FIRST%'", "COLUMN_NAME LIKE '%LAST%'"]);
        return [$detected ?? 'dbo.TblContacts', $this->listColumns($detected ?? 'dbo.TblContacts')];
    }

    /**
     * @return array{string, array<int,string>}
     */
    protected function detectDebtTable(): array
    {
        foreach (['dbo.TblDebts', 'TblDebts', 'dbo.Debts', 'Debts'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        $detected = $this->detectBestTable(["COLUMN_NAME LIKE '%SUMMONS%'", "COLUMN_NAME LIKE '%VERIFIED%'", "COLUMN_NAME LIKE '%DEBT%'"]);
        return [$detected ?? 'dbo.TblDebts', $this->listColumns($detected ?? 'dbo.TblDebts')];
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectPlanTable(): array
    {
        foreach (['dbo.EnrollmentPlan', 'EnrollmentPlan', 'dbo.TblEnrollmentPlan', 'TblEnrollmentPlan'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * Return the first table from the list that exists, or null.
     *
     * @param  array<int,string>  $candidates
     */
    protected function firstExistingTable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectDefaultsTable(): array
    {
        foreach (['dbo.Enrollment_Defaults2', 'Enrollment_Defaults2', 'dbo.EnrollmentDefaults2', 'EnrollmentDefaults2'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectCreditorTable(): array
    {
        foreach (['dbo.TblCreditors', 'TblCreditors', 'dbo.Creditors', 'Creditors'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectBalanceTable(): array
    {
        foreach (['dbo.Contact_Balances', 'Contact_Balances', 'dbo.TblContactBalances', 'TblContactBalances'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectNotesTable(): array
    {
        foreach (['dbo.Debt_Notes', 'Debt_Notes', 'dbo.TblDebtNotes', 'TblDebtNotes'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * @return array{?string, array<int,string>}
     */
    protected function detectCustomFieldTable(): array
    {
        foreach (['dbo.CustomField_Data', 'CustomField_Data', 'dbo.CustomFieldData', 'CustomFieldData'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }
        return [null, []];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $candidates
     */
    protected function firstMatchingColumn(array $columns, array $candidates): ?string
    {
        $lookup = [];
        foreach ($columns as $col) {
            $lookup[strtolower($this->sanitizeColumnName($col))] = $this->sanitizeColumnName($col);
        }

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }

        foreach ($columns as $col) {
            $clean = strtolower($this->sanitizeColumnName($col));
            foreach ($candidates as $candidate) {
                $cand = strtolower($candidate);
                if ($cand !== '' && str_contains($clean, $cand)) {
                    return $this->sanitizeColumnName($col);
                }
            }
        }

        return null;
    }

    protected function sanitizeColumnName(string $column): string
    {
        $col = trim($column);
        $col = ltrim($col, '[');
        $col = rtrim($col, ']');
        return $col;
    }

    /**
     * @return array{?string,string}
     */
    protected function splitObjectName(string $name): array
    {
        $parts = explode('.', $name, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return [null, $name];
    }

    /**
     * Check if a table exists in the SQL Server database.
     */
    protected function tableExists(string $table): bool
    {
        [$schema, $name] = $this->splitObjectName($table);
        $schema = $schema ?: 'dbo';

        try {
            $row = DB::connection($this->connection)->selectOne(
                'SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$schema, $name]
            );

            return ((int) ($row->cnt ?? 0)) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List columns for a table (uppercased).
     *
     * @return array<int,string>
     */
    protected function listColumns(string $table): array
    {
        [$schema, $name] = $this->splitObjectName($table);
        $schema = $schema ?: 'dbo';

        try {
            $rows = DB::connection($this->connection)->select(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$schema, $name]
            );

            return array_values(array_map(static fn($row) => strtoupper((string) ($row->COLUMN_NAME ?? '')), $rows));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Detect best matching table by scoring candidate columns.
     */
    protected function detectBestTable(array $likeParts): ?string
    {
        try {
            $rows = DB::connection($this->connection)->select(
                'SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE ' . implode(' OR ', $likeParts)
            );

            $scores = [];
            foreach ($rows as $row) {
                $schema = (string) ($row->TABLE_SCHEMA ?? '');
                $tableName = (string) ($row->TABLE_NAME ?? '');
                $table = ($schema !== '' ? $schema . '.' : '') . $tableName;
                $col = strtoupper((string) ($row->COLUMN_NAME ?? ''));

                if ($table === '' || $tableName === '' || $col === '') {
                    continue;
                }

                $scores[$table] ??= 0;
                if (str_contains($col, 'ID')) $scores[$table] += 2;
                if (str_contains($col, 'FIRST')) $scores[$table] += 3;
                if (str_contains($col, 'LAST')) $scores[$table] += 3;
                if (str_contains($col, 'SUMMONS')) $scores[$table] += 4;
                if (str_contains($col, 'DEBT')) $scores[$table] += 4;
            }

            if (empty($scores)) {
                return null;
            }

            arsort($scores);

            return array_key_first($scores) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
