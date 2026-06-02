<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\EpfAuditReportRepository;
use Cmd\Reports\Services\EpfAuditExcelFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EpfAuditReportController extends Controller
{
    public function __construct(protected EpfAuditReportRepository $repo) {}

    public function index(Request $request): View|StreamedResponse
    {
        $cutoff = $this->resolveCutoff((string) $request->query('cutoff', ''));
        $fromDate = $this->resolveFromDate((string) $request->query('from_date', ''));
        $tab = strtolower((string) $request->query('tab', 'epfs'));
        if (!in_array($tab, ['epfs', 'advances', 'summary'], true)) {
            $tab = 'epfs';
        }

        $perPage = (int) $request->query('per_page', 50);
        if ($perPage < 10) {
            $perPage = 10;
        }
        if ($perPage > 500) {
            $perPage = 500;
        }
        $page = max(1, (int) $request->query('page', 1));

        if ($request->query('export') === 'xlsx') {
            return $this->exportXlsx($cutoff, $fromDate);
        }

        $rows = match ($tab) {
            'advances' => $this->repo->getAdvances($cutoff, $fromDate),
            'summary'  => $this->repo->getSummary($cutoff, $fromDate),
            default    => $this->repo->getEpfs($cutoff, $fromDate),
        };

        $columns = match ($tab) {
            'advances' => $this->repo->advancesColumns(),
            'summary'  => $this->repo->summaryColumns(),
            default    => $this->repo->epfColumns(),
        };

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        return view('reports::reports.epf_audit', [
            'cutoff'   => $cutoff,
            'fromDate' => $fromDate,
            'tab'      => $tab,
            'rows'     => $pageRows,
            'columns'  => $columns,
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
        ]);
    }

    private function exportXlsx(string $cutoff, string $fromDate): StreamedResponse
    {
        $epfs     = $this->repo->getEpfs($cutoff, $fromDate);
        $advances = $this->repo->getAdvances($cutoff, $fromDate);
        $summary  = $this->repo->getSummary($cutoff, $fromDate);

        $path = (new EpfAuditExcelFormatter())->buildWorkbook(
            $epfs,
            $advances,
            $summary,
            $this->repo->epfColumns(),
            $this->repo->advancesColumns(),
            $this->repo->summaryColumns(),
            $cutoff
        );

        $filename = 'epf_audit_' . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(
            function () use ($path) {
                readfile($path);
                @unlink($path);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function resolveCutoff(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }
        return date('Y-m-d', strtotime('first day of next month'));
    }

    private function resolveFromDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }
        return date('Y-m-d', strtotime('first day of last month'));
    }
}
