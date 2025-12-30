<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'report_type' => 'Report',
            'submitted_date' => 'Submitted Date',
            'agent' => 'Agent',
            'debt_amount' => 'Debt Amount',
            'llg_id' => 'LLG ID',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        $reportType = $filters['report_type'] ?? 'Invoice';
        $reportTypeLiteral = str_replace("'", "''", $reportType);

        $query = $this->table('TblEnrollment')
            ->selectRaw("'" . $reportTypeLiteral . "' AS report_type")
            ->selectRaw('Submitted_Date AS submitted_date')
            ->selectRaw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent")
            ->selectRaw('Debt_Amount AS debt_amount')
            ->addSelect('LLG_ID as llg_id')
            ->whereNotNull('LLG_ID');

        if (!empty($filters['agent'])) {
            $query->where('Agent', 'like', '%' . $filters['agent'] . '%');
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('Submitted_Date');
    }
}
