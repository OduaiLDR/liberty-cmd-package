<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LeaderboardReportRequest;
use Cmd\Reports\Repositories\LeaderboardReportRepository;
use Cmd\Reports\Support\LeaderboardExport;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaderboardReportController extends Controller
{
    public function __construct(protected LeaderboardReportRepository $repository)
    {
    }

    public function index(LeaderboardReportRequest $request): View|StreamedResponse
    {
        $category = (string) $request->input('category', 'Deals Enrolled');
        if (! in_array($category, LeaderboardReportRepository::CATEGORIES, true)) {
            $category = 'Deals Enrolled';
        }

        $periods = $this->repository->periodsFor($category);
        $period = $request->input('period');
        if (! $period || ! $periods->contains($period)) {
            $period = $periods->first() ?? 'Monthly';
        }

        if ($request->input('export') === 'csv') {
            return $this->exportXlsx($category, $period);
        }

        $window = $this->repository->resolveWindow($period);

        return view('reports::reports.leaderboard', [
            'category' => $category,
            'period' => $period,
            'periods' => $periods,
            'categories' => LeaderboardReportRepository::CATEGORIES,
            'layout' => $this->repository->layout($category),
            'settings' => $this->repository->settings($category, $period),
            'window' => $window,
            'title' => $this->repository->titleLabel($category, $period),
            'header' => $this->repository->currentHeader($category, $period, $window),
            'currentLeaders' => $this->repository->currentLeaders($category, $period),
            'currentCompany' => $this->repository->currentCompany($category, $period),
            'recordHolders' => $this->repository->recordHolders($category, $period),
            'companyRecord' => $this->repository->companyRecord($category, $period),
        ]);
    }

    /**
     * Standalone Total Records page (all-time standings, category/period-independent).
     */
    public function totalRecords(): View
    {
        return view('reports::reports.leaderboard_total_records', [
            'totalRecords' => $this->repository->totalRecords(),
        ]);
    }

    /**
     * Full report as a styled .xlsx (all four sections, like the Excel sheet).
     */
    protected function exportXlsx(string $category, string $period): StreamedResponse
    {
        $spreadsheet = LeaderboardExport::spreadsheet($this->repository, $category, $period);
        $writer = new Xlsx($spreadsheet);

        $filename = 'leaderboard_' . strtolower(str_replace(' ', '_', $category)) . '_'
            . strtolower($period) . '_' . Carbon::now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
