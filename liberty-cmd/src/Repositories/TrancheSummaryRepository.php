<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TrancheSummaryRepository extends SqlSrvRepository
{
    protected function baseQuery(?string $from = null, ?string $to = null)
    {
        $connection = $this->connection();

        $salesQuery = $connection->table('TblDebtTrancheSales as ts');

        if ($from) {
            $salesQuery->whereDate('ts.Payment_Date', '>=', $from);
        }
        if ($to) {
            $salesQuery->whereDate('ts.Payment_Date', '<=', $to);
        }

        $trancheFilter = null;
        if ($from || $to) {
            $trancheFilter = (clone $salesQuery)->select('ts.Tranche')->distinct();
        }

        $enrollmentAgg = $connection->table('TblEnrollment')
            ->selectRaw('Tranche,
                COUNT(*) AS Count_Total,
                SUM(CASE WHEN Enrollment_Plan NOT LIKE \'PLAW%\' AND UPPER(Enrollment_Plan) NOT LIKE \'%PROGRESS%\' THEN 1 ELSE 0 END) AS Count_LDR,
                SUM(CASE WHEN Enrollment_Plan LIKE \'PLAW%\' THEN 1 ELSE 0 END) AS Count_PLAW,
                SUM(CASE WHEN UPPER(Enrollment_Plan) LIKE \'%PROGRESS%\' THEN 1 ELSE 0 END) AS Count_PROGRESS,
                SUM(CASE WHEN Lookback_Date IS NOT NULL THEN Sold_Debt ELSE 0 END) AS SoldDebt_Lookback,
                SUM(Sold_Debt * EPF_Rate) AS EPF_All,
                SUM(CASE WHEN Lookback_Date IS NULL AND Cancel_Date IS NULL THEN Sold_Debt * EPF_Rate ELSE 0 END) AS EPF_Pending')
            ->groupBy('Tranche');

        if ($trancheFilter) {
            $enrollmentAgg->whereIn('Tranche', $trancheFilter);
        }

        $epfAgg = $connection->table('TblEPFs as ep')
            ->join('TblEnrollment as e', 'ep.LLG_ID', '=', 'e.LLG_ID')
            ->whereNotNull('ep.Cleared_Date')
            ->whereIn('ep.Paid_To', [31213, 35285])
            ->selectRaw('e.Tranche, SUM(ep.Amount) AS EPF_Amount')
            ->groupBy('e.Tranche');

        if ($trancheFilter) {
            $epfAgg->whereIn('e.Tranche', $trancheFilter);
        }

        $epfdAgg = $connection->table('TblEPFDistributions')
            ->selectRaw('Tranche, SUM(Amount) AS EPFD_Amount')
            ->groupBy('Tranche');

        if ($trancheFilter) {
            $epfdAgg->whereIn('Tranche', $trancheFilter);
        }

        $paymentExpr = 'COALESCE(ts.Payment, 0)';
        $soldLookbackExpr = 'COALESCE(e.SoldDebt_Lookback, 0)';
        $epfAmountExpr = 'COALESCE(p.EPF_Amount, 0)';
        $epfdAmountExpr = 'COALESCE(d.EPFD_Amount, 0)';

        $kExpr = "ROUND({$soldLookbackExpr} * 0.08, 2)";
        $nExpr = "ROUND({$paymentExpr} * 1.10, 2)";
        $qExpr = "ROUND({$epfAmountExpr} + {$epfdAmountExpr}, 2)";
        $rBaseExpr = "CASE WHEN {$qExpr} <= {$nExpr} THEN {$qExpr} ELSE {$nExpr} END";
        $rExpr = "ROUND({$rBaseExpr}, 2)";
        $sExpr = "ROUND(CASE WHEN {$nExpr} - {$qExpr} > 0 THEN {$nExpr} - {$qExpr} ELSE 0 END, 2)";
        $tExpr = "ROUND(CASE WHEN {$qExpr} - {$nExpr} > 0 THEN {$qExpr} - {$nExpr} ELSE 0 END, 2)";
        $uExpr = "ROUND(CASE WHEN {$nExpr} = 0 THEN 0 ELSE {$rBaseExpr} / NULLIF({$nExpr}, 0) END, 4)";

        return $salesQuery
            ->select([
                'ts.Tranche',
                'ts.Payment_Date',
                'ts.Report_Date',
                'ts.Total_Debt',
                'ts.Payment',
                'ts.Flip_Date',
                'e.Count_LDR',
                'e.Count_PLAW',
                'e.Count_PROGRESS',
                'e.Count_Total',
                'e.SoldDebt_Lookback',
                'e.EPF_All',
                'e.EPF_Pending',
                'p.EPF_Amount',
                'd.EPFD_Amount',
            ])
            ->leftJoinSub($enrollmentAgg, 'e', 'e.Tranche', '=', 'ts.Tranche')
            ->leftJoinSub($epfAgg, 'p', 'p.Tranche', '=', 'ts.Tranche')
            ->leftJoinSub($epfdAgg, 'd', 'd.Tranche', '=', 'ts.Tranche')
            ->selectRaw("{$kExpr} as K_EightPercentOfLookback")
            ->selectRaw("{$nExpr} as N_PaymentPlus10")
            ->selectRaw("{$qExpr} as Q_EpfTotal")
            ->selectRaw("{$rExpr} as R_MinQN")
            ->selectRaw("{$sExpr} as S_MaxNMinusQ")
            ->selectRaw("{$tExpr} as T_MaxQMinusN")
            ->selectRaw("{$uExpr} as U_Ratio")
            ->orderBy('ts.Tranche', 'asc');
    }

    public function all(?string $from = null, ?string $to = null): Collection
    {
        return $this->baseQuery($from, $to)->get();
    }

    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to),
            $perPage
        );
    }
}
