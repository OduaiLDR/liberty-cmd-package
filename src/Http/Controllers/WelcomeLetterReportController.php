<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\WelcomeLetterReportRequest;
use Cmd\Reports\Repositories\WelcomeLetterReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WelcomeLetterReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected WelcomeLetterReportRepository $repository
    ) {
    }

    public function index(WelcomeLetterReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.welcome_letter', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'opts' => $options,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'client' => $this->trimOrNull($request->input('client')),
            'plan' => $this->trimOrNull($request->input('plan')),
            'frequency' => $this->trimOrNull($request->input('frequency')),
            'enrolled_from' => $this->trimOrNull($request->input('enrolled_from')),
            'enrolled_to' => $this->trimOrNull($request->input('enrolled_to')),
            'payment_from' => $this->trimOrNull($request->input('payment_from')),
            'payment_to' => $this->trimOrNull($request->input('payment_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'payment_min' => $this->trimOrNull($request->input('payment_min')),
            'payment_max' => $this->trimOrNull($request->input('payment_max')),
        ];
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'welcome_letter_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Client',
                'Plan',
                'Enrolled Debt Accounts',
                'Enrolled Debt',
                'Payment Date',
                'Payment',
                'LLG ID',
                'Frequency',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->client,
                    $row->plan,
                    $row->enrolled_debt_accounts,
                    $this->formatCsvCurrency($row->enrolled_debt),
                    $this->formatCsvDate($row->payment_date),
                    $this->formatCsvCurrency($row->payment),
                    $row->llg_id,
                    $row->frequency,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
