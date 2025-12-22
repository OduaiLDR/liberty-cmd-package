<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LdrPastDueReportRequest;
use Cmd\Reports\Repositories\LdrPastDueReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LdrPastDueReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected LdrPastDueReportRepository $repository
    ) {
    }

    public function index(LdrPastDueReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $request->only([
            'contact_id',
            'trans_type',
            'active',
            'cancelled',
            'process_from',
            'process_to',
            'cleared_from',
            'cleared_to',
            'returned_from',
            'returned_to',
            'amount_min',
            'amount_max',
        ]);

        $columns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows, $columns);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.ldr_past_due', [
            'reports' => $reports,
            'columns' => $columns,
            'filters' => $filters,
            'perPage' => $perPage,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @param  array<string, string>  $columns
     */
    protected function exportCsv(Collection $rows, array $columns): StreamedResponse
    {
        $filename = 'ldr_past_due_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $alias => $label) {
                    $value = $row->{$alias} ?? '';
                    if (is_numeric($value)) {
                        $record[] = number_format((float) $value, 2, '.', ',');
                        continue;
                    }
                    $record[] = $value;
                }
                fputcsv($out, $record);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
