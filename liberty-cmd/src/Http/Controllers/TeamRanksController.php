<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\TeamRanksRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamRanksController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected TeamRanksRepository $repository
    ) {
    }

    public function index(Request $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $from = $request->input('from');
        $to = $request->input('to');
        $range = $request->input('range');
        $dataSource = $request->input('data_source', 'All Data Sources');

        if (!$from && !$to && !$range) {
            [$from, $to] = $this->defaultWindow();
        }

        if ($range && $range !== 'custom') {
            [$from, $to] = $this->resolveRange($range);
        }

        $result = $this->repository->data($from, $to, $dataSource);
        $opts = $result['options'];

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($result['agents'], $result['teams'], $result['company']);
        }

        $agents = $this->paginateAgents($result['agents'], $perPage, $request);

        return view('reports::reports.team_ranks', [
            'agents' => $agents,
            'teams' => $result['teams'],
            'company' => $result['company'],
            'opts' => $opts,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'perPage' => $perPage,
            'dataSource' => $dataSource,
        ]);
    }

    protected function resolveRange(string $range): array
    {
        $today = now()->startOfDay();
        return match ($range) {
            'all' => [null, null],
            'today' => [$today->toDateString(), $today->toDateString()],
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => (is_numeric($range) && (int)$range > 0)
                ? [
                    $today->copy()->subDays(((int) $range) - 1)->toDateString(),
                    $today->toDateString(),
                ]
                : [null, null],
        };
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $value = (int) ($perPage ?? 25);
        return $value > 0 && $value <= 1000 ? $value : 25;
    }

    protected function defaultWindow(): array
    {
        $today = now()->startOfDay();
        $start = $today->copy()->subDays(33);
        $end = $today->copy()->subDays(3);

        return [$start->toDateString(), $end->toDateString()];
    }

    protected function paginateAgents(Collection $agents, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $agents->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $agents->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    protected function exportCsv(Collection $agents, Collection $teams, object $company): StreamedResponse
    {
        $filename = 'team_ranks_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($agents, $teams, $company) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Team', 'Agent', 'Contacts', 'WCC', 'Cancels', 'NSFs', 'Enrolled Debt', 'Net', 'Ratio', 'Rank Ratio', 'Rank WCC', 'Rank Debt', 'Score']);

            foreach ($agents as $r) {
                fputcsv($out, [
                    $r->team,
                    $r->agent,
                    (int) $r->contacts,
                    (int) $r->wcc,
                    (int) $r->cancels,
                    (int) $r->nsfs,
                    $this->formatCsvCurrency($r->enrolled_debt),
                    (int) $r->net,
                    $r->ratio === null ? '' : number_format((float) $r->ratio, 4),
                    $r->rank_ratio ? number_format((float) $r->rank_ratio, 2) : '',
                    $r->rank_wcc ? number_format((float) $r->rank_wcc, 2) : '',
                    $r->rank_debt ? number_format((float) $r->rank_debt, 2) : '',
                    $r->score ? number_format((float) $r->score, 2) : '',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Teams']);
            foreach ($teams as $t) {
                fputcsv($out, [
                    $t->team,
                    $t->agent,
                    (int) $t->contacts,
                    (int) $t->wcc,
                    (int) $t->cancels,
                    (int) $t->nsfs,
                    $this->formatCsvCurrency($t->enrolled_debt),
                    (int) $t->net,
                    $t->ratio === null ? '' : number_format((float) $t->ratio, 4),
                    $t->rank_ratio ? number_format((float) $t->rank_ratio, 2) : '',
                    $t->rank_wcc ? number_format((float) $t->rank_wcc, 2) : '',
                    $t->rank_debt ? number_format((float) $t->rank_debt, 2) : '',
                    $t->score ? number_format((float) $t->score, 2) : '',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Company-Wide']);
            fputcsv($out, [
                $company->team,
                $company->agent,
                (int) $company->contacts,
                (int) $company->wcc,
                (int) $company->cancels,
                (int) $company->nsfs,
                $this->formatCsvCurrency($company->enrolled_debt),
                (int) $company->net,
                $company->ratio === null ? '' : number_format((float) $company->ratio, 4),
                '', '', '', '',
            ]);

            fclose($out);
        }, $filename, $headers);
    }
}
