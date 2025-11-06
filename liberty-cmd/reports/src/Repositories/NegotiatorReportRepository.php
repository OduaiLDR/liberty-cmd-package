<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class NegotiatorReportRepository extends SqlSrvRepository
{
    public function options(): array
    {
        $base = $this->table('TblEnrollment');
        $pluck = fn(string $column, int $limit = 500): Collection => $this->distinctValues($base, $column, $limit);

        $assignBase = $this->table('TblNegotiatorAssignments');
        $pluckAssign = fn(string $column, int $limit = 500): Collection => $this->distinctValues($assignBase, $column, $limit);

        return [
            'negotiators' => $pluck('Negotiator'),
            'ngos' => $pluck('Drop_Name'),
            'enrollment_statuses' => $pluck('Enrollment_Status'),
            'assignment_statuses' => $pluckAssign('Status'),
            'creditors' => $pluckAssign('Creditor'),
            'collection_companies' => $pluckAssign('Collection_Company'),
        ];
    }

    public function all(
        ?string $from = null,
        ?string $to = null,
        string $dateField = 'payment',
        array $filters = []
    ): Collection {
        return $this->baseQuery($from, $to, $dateField, $filters)->get()->pipe(fn($rows) => $this->hydrate($rows));
    }

    public function paginate(
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
        string $dateField = 'payment',
        array $filters = []
    ): LengthAwarePaginator {
        $paginator = $this->paginateBuilder(
            $this->baseQuery($from, $to, $dateField, $filters)
                ->orderBy('en.Negotiator')
                ->orderByRaw('COALESCE(c.Client, en.Client)'),
            $perPage
        );

        $paginator->setCollection($this->hydrate($paginator->getCollection()));

        return $paginator;
    }

    protected function baseQuery(
        ?string $from,
        ?string $to,
        string $dateField,
        array $filters
    ): Builder {
        $conn = $this->connection();

        [$twoMonthsAgo, $lastMonthEnd] = array_slice($this->balanceSnapshotDates(), 0, 2);

        $enrollmentBase = $this->buildEnrollmentBase($conn, $from, $to, $dateField, $filters);
        $eligibleIds = $this->enrollmentIdsSubquery($enrollmentBase);

        $settlements = $conn->table('TblNegotiatorSettlementSummary')
            ->selectRaw("CONCAT('LLG-', Contact_ID) as CID")
            ->selectRaw('COUNT(DISTINCT Settlement_ID) as Settlements')
            ->whereIn('Contact_ID', (clone $eligibleIds))
            ->groupBy('Contact_ID');

        $balanceSnapshot = function (string $date) use ($conn, $eligibleIds) {
            return $conn->table('TblBalancesHistory')
                ->select('LLG_ID', 'Balance')
                ->whereDate('Balance_Date', '=', $date)
                ->whereIn('LLG_ID', (clone $eligibleIds));
        };

        $query = $conn->query()
            ->fromSub($enrollmentBase, 'en')
            ->leftJoin('TblContacts as c', 'c.LLG_ID', '=', 'en.LLG_ID')
            ->leftJoin('TblBalances as b', 'en.CID', '=', 'b.CID')
            ->leftJoin('TblNegotiatorAssignments as n', 'en.CID', '=', 'n.CID')
            ->leftJoin('TblNegotiatorDebts as d', 'n.Debt_ID', '=', 'd.Debt_ID')
            ->leftJoinSub($settlements, 's', 'en.CID', '=', 's.CID')
            ->leftJoin('TblCreditorGroups as cg', function ($join) {
                $join->onRaw('UPPER(n.Creditor) = UPPER(cg.Creditor_Name)');
            })
            ->leftJoinSub($balanceSnapshot($twoMonthsAgo), 'bh1', 'bh1.LLG_ID', '=', 'en.LLG_ID')
            ->leftJoinSub($balanceSnapshot($lastMonthEnd), 'bh2', 'bh2.LLG_ID', '=', 'en.LLG_ID')
            ->select([
                'en.CID',
                'en.Enrollment_Status',
                'n.Debt_ID',
                'b.Balance',
                'en.Payments',
                'en.Agent',
                'en.Drop_Name as NGO',
                'en.Negotiator',
                'en.Negotiator_Assigned_Date',
                'n.Follow_Up_Date',
                'n.Account_Not_Ready_Date',
                'n.Account_Not_Ready_Reason',
                'd.Last_Payment_Date',
                'd.Settlement_Date',
                'n.Ready_To_Settle_Date',
                'en.Welcome_Call_Date',
                'en.First_Payment_Date as WCC_Date',
                'en.Submitted_Date',
                'en.Cancel_Date',
                'en.NSF_Date',
            ])
            ->selectRaw('COALESCE(c.Client, en.Client) as Contact_Name')
            ->selectRaw('COALESCE(c.Debt_Amount, en.Debt_Amount) as Debt_Amount')
            ->selectRaw('COALESCE(n.Creditor, NULL) as Creditor')
            ->selectRaw('COALESCE(n.Collection_Company, NULL) as Collection_Company')
            ->selectRaw('cg.Group_Name as Creditor_Group')
            ->selectRaw('COALESCE(n.Status, en.Category) as Assignment_Status')
            ->selectRaw('en.Payment_Date as Payment_Date')
            ->selectRaw('COALESCE(s.Settlements, 0) as Settlements')
            ->selectRaw('bh1.Balance as Balance_Two_Months_Ago')
            ->selectRaw('bh2.Balance as Balance_Last_Month');

        if (!empty($filters['assignment_status'])) {
            $query->where('n.Status', '=', $filters['assignment_status']);
        }
        if (!empty($filters['ready_flag'])) {
            if ($filters['ready_flag'] === 'ready') {
                $query->whereNotNull('n.Ready_To_Settle_Date');
            } elseif ($filters['ready_flag'] === 'not_ready') {
                $query->whereNull('n.Ready_To_Settle_Date');
            }
        }
        if (!empty($filters['creditor'])) {
            $query->where('n.Creditor', 'like', '%' . trim((string) $filters['creditor']) . '%');
        }
        if (!empty($filters['collection_company'])) {
            $query->where('n.Collection_Company', 'like', '%' . trim((string) $filters['collection_company']) . '%');
        }
        if (!empty($filters['debt_min'])) {
            $query->whereRaw('COALESCE(c.Debt_Amount, en.Debt_Amount) >= ?', [(float) $filters['debt_min']]);
        }
        if (!empty($filters['debt_max'])) {
            $query->whereRaw('COALESCE(c.Debt_Amount, en.Debt_Amount) <= ?', [(float) $filters['debt_max']]);
        }
        if (!empty($filters['follow_up_from'])) {
            $query->whereDate('n.Follow_Up_Date', '>=', $filters['follow_up_from']);
        }
        if (!empty($filters['follow_up_to'])) {
            $query->whereDate('n.Follow_Up_Date', '<=', $filters['follow_up_to']);
        }
        if (!empty($filters['ready_from'])) {
            $query->whereDate('n.Ready_To_Settle_Date', '>=', $filters['ready_from']);
        }
        if (!empty($filters['ready_to'])) {
            $query->whereDate('n.Ready_To_Settle_Date', '<=', $filters['ready_to']);
        }
        if (!empty($filters['settlement_from'])) {
            $query->whereDate('d.Settlement_Date', '>=', $filters['settlement_from']);
        }
        if (!empty($filters['settlement_to'])) {
            $query->whereDate('d.Settlement_Date', '<=', $filters['settlement_to']);
        }
        if (!empty($filters['last_payment_from'])) {
            $query->whereDate('d.Last_Payment_Date', '>=', $filters['last_payment_from']);
        }
        if (!empty($filters['last_payment_to'])) {
            $query->whereDate('d.Last_Payment_Date', '<=', $filters['last_payment_to']);
        }
        if (!empty($filters['report_type'])) {
            if ($filters['report_type'] === 'ready') {
                $query->whereNotNull('n.Ready_To_Settle_Date')->whereNull('d.Settlement_Date');
            } elseif ($filters['report_type'] === 'not_ready') {
                $query->whereNull('n.Ready_To_Settle_Date')->whereNull('d.Settlement_Date');
            } elseif ($filters['report_type'] === 'settled') {
                $query->whereNotNull('d.Settlement_Date');
            }
        }

        return $query;
    }

    protected function buildEnrollmentBase(
        ConnectionInterface $conn,
        ?string $from,
        ?string $to,
        string $dateField,
        array $filters
    ): Builder {
        $query = $conn->table('TblEnrollment')
            ->select([
                'LLG_ID',
                'Client',
                'Enrollment_Status',
                'Negotiator',
                'Category',
                'Drop_Name',
                'Agent',
                'Payments',
                'Debt_Amount',
                'Negotiator_Assigned_Date',
                'First_Payment_Date',
                'Welcome_Call_Date',
                'Submitted_Date',
                'Cancel_Date',
                'NSF_Date',
            ])
            ->selectRaw("CONCAT('LLG-', LLG_ID) as CID")
            ->selectRaw('COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1) as Payment_Date');

        $this->applyEnrollmentDateFilter($query, $dateField, $from, $to);
        $this->applyEnrollmentFilters($query, $filters);

        return $query;
    }

    protected function enrollmentIdsSubquery(Builder $base): Builder
    {
        return (clone $base)->select('LLG_ID')->distinct();
    }

    protected function applyEnrollmentDateFilter(Builder $query, string $dateField, ?string $from, ?string $to): void
    {
        if ($dateField === 'welcome_call') {
            if ($from) {
                $query->whereDate('Welcome_Call_Date', '>=', $from);
            }
            if ($to) {
                $query->whereDate('Welcome_Call_Date', '<=', $to);
            }
            return;
        }

        if ($dateField === 'submitted') {
            if ($from) {
                $query->whereDate('Submitted_Date', '>=', $from);
            }
            if ($to) {
                $query->whereDate('Submitted_Date', '<=', $to);
            }
            return;
        }

        if ($from) {
            $query->whereRaw('CAST(COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1) AS date) >= ?', [$from]);
        }
        if ($to) {
            $query->whereRaw('CAST(COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1) AS date) <= ?', [$to]);
        }
    }

    protected function applyEnrollmentFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['negotiator'])) {
            $query->where('Negotiator', '=', $filters['negotiator']);
        }

        if (!empty($filters['ngo']) && $filters['ngo'] !== 'all') {
            $query->where('Drop_Name', '=', $filters['ngo']);
        }

        if (!empty($filters['enrollment_status'])) {
            switch ($filters['enrollment_status']) {
                case 'active':
                    $query->whereNull('Cancel_Date')->whereNull('NSF_Date');
                    break;
                case 'cancels':
                    $query->whereNotNull('Cancel_Date');
                    break;
                case 'nsfs':
                    $query->whereNotNull('NSF_Date');
                    break;
                case 'not_closed':
                    $query->whereNull('Cancel_Date');
                    break;
                default:
                    $query->where('Enrollment_Status', '=', $filters['enrollment_status']);
            }
        }
    }

    protected function hydrate(Collection $rows): Collection
    {
        $today = Carbon::today();

        return $rows->map(function ($row) use ($today) {
            $debt = (float) ($row->Debt_Amount ?? 0);
            $balance = (float) ($row->Balance ?? 0);
            $row->Debt_Tier = $this->resolveDebtTier($debt);
            $row->Debt_Balance_Ratio = ($balance > 0) ? ($debt / $balance) : null;

            $activityDates = collect([
                $row->Last_Payment_Date ?? null,
                $row->Settlement_Date ?? null,
                $row->Ready_To_Settle_Date ?? null,
                $row->Account_Not_Ready_Date ?? null,
                $row->Follow_Up_Date ?? null,
            ])->filter();

            $lastActivity = $activityDates->map(fn($date) => Carbon::parse($date))->sort()->last();
            $row->Last_Activity_Date = $lastActivity ? $lastActivity->format('Y-m-d') : null;
            $row->Days_Since_Activity = $lastActivity ? $lastActivity->diffInDays($today) : null;

            $row->Balance_Two_Months_Ago = $row->Balance_Two_Months_Ago !== null ? (float) $row->Balance_Two_Months_Ago : null;
            $row->Balance_Last_Month = $row->Balance_Last_Month !== null ? (float) $row->Balance_Last_Month : null;
            $row->Balance_Current = $row->Balance;
            $row->Settlements = (int) ($row->Settlements ?? 0);
            $row->Send_POA = 'Send POA';

            return $row;
        });
    }

    protected function resolveDebtTier(float $amount): ?int
    {
        if ($amount <= 0) return null;
        return match (true) {
            $amount < 12000 => 1,
            $amount < 15001 => 2,
            $amount < 19000 => 3,
            $amount < 26000 => 4,
            $amount < 35000 => 5,
            $amount < 50000 => 6,
            $amount < 65000 => 7,
            $amount < 80000 => 8,
            default => 9,
        };
    }

    protected function balanceSnapshotDates(): array
    {
        $today = Carbon::today();

        $twoMonthsAgo = $today->copy()->subMonthsNoOverflow(2)->endOfMonth();
        $lastMonthEnd = $today->copy()->subMonthsNoOverflow(1)->endOfMonth();
        $currentMonthEnd = $today->copy()->endOfMonth();

        return [
            $twoMonthsAgo->toDateString(),
            $lastMonthEnd->toDateString(),
            $currentMonthEnd->toDateString(),
        ];
    }
}
