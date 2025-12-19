<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\CreditorContactsReportRequest;
use Cmd\Reports\Repositories\CreditorContactsReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditorContactsReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected CreditorContactsReportRepository $repository
    ) {
    }

    public function index(CreditorContactsReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $request->only([
            'creditor_name',
            'parent_account',
            'poa_exclusion',
            'email',
            'fax',
            'contact_name',
            'contact_phone',
            'creditor_address',
            'notes',
        ]);

        $columns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows, $columns);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.creditor_contacts', [
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
        $filename = 'creditor_contacts_' . Carbon::now()->format('Ymd_His') . '.csv';
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
