<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\EnrollmentFrequencyReportRequest;
use Cmd\Reports\Repositories\EnrollmentFrequencyReportRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrollmentFrequencyReportController extends Controller
{
    public function __construct(
        protected EnrollmentFrequencyReportRepository $repository
    ) {
    }

    public function index(EnrollmentFrequencyReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $request->only([
            'id',
            'first_name',
            'last_name',
            'frequency',
            'created_from',
            'created_to',
            'assigned_from',
            'assigned_to',
        ]);

        $columns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows, $columns);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.enrollment_frequency', [
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
        $filename = 'enrollment_frequency_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                $record = [];
                foreach (array_keys($columns) as $alias) {
                    $value = $row->{$alias} ?? '';
                    if (is_numeric($value)) {
                        $record[] = number_format((float) $value, 0, '.', ',');
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
