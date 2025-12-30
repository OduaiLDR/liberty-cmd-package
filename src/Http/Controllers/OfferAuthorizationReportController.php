<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\OfferAuthorizationReportRequest;
use Cmd\Reports\Repositories\OfferAuthorizationReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferAuthorizationReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected OfferAuthorizationReportRepository $repository
    ) {
    }

    public function index(OfferAuthorizationReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.offer_authorization', [
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
            'offer_id' => $this->trimOrNull($request->input('offer_id')),
            'llg_id' => $this->trimOrNull($request->input('llg_id')),
            'title' => $this->trimOrNull($request->input('title')),
            'firstname' => $this->trimOrNull($request->input('firstname')),
            'lastname' => $this->trimOrNull($request->input('lastname')),
            'address' => $this->trimOrNull($request->input('address')),
            'address2' => $this->trimOrNull($request->input('address2')),
            'city' => $this->trimOrNull($request->input('city')),
            'state' => $this->trimOrNull($request->input('state')),
            'zip' => $this->trimOrNull($request->input('zip')),
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
        $filename = 'offer_authorization_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Offer ID',
                'LLG ID',
                'Title',
                'First Name',
                'Last Name',
                'Address',
                'Address 2',
                'City',
                'State',
                'ZIP',
                'Return Address',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->offer_id,
                    $row->llg_id,
                    $row->title,
                    $row->firstname,
                    $row->lastname,
                    $row->address,
                    $row->address2,
                    $row->city,
                    $row->state,
                    $row->zip,
                    $row->return_address,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
