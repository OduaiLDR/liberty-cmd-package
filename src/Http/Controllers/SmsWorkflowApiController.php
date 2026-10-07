<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\MailDropExportRequest;
use Cmd\Reports\Http\Requests\MarketingInvoiceRequest;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Authorization is supplied by the CMD host's session and report-policy middleware. */
class SmsWorkflowApiController extends Controller
{
    public function __construct(
        protected MailDropExportRepository $drops,
        protected MarketingReportRepository $marketing,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'target' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'page' => ['nullable', 'integer', 'min:1'],
            'count_candidates' => ['nullable', 'boolean'],
            'count_pks' => ['sometimes', 'array', 'min:1', 'max:10'],
            'count_pks.*' => ['required', 'integer', 'min:1', 'distinct'],
            'drop_pks' => ['sometimes', 'array', 'min:1', 'max:500'],
            'drop_pks.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        $this->ensureSchemaReady();
        $target = (int) ($data['target'] ?? 0);
        $page = (int) ($data['page'] ?? 1);
        $manual = isset($data['drop_pks']);
        $countCandidates = (int) ($data['count_candidates'] ?? 0) === 1;
        $countIds = isset($data['count_pks']);
        if (($countCandidates || $countIds) && ($manual || $target < 1 || ($countCandidates && $countIds))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['target' => 'A phone target and one selection mode are required.']);
        }
        if ($countCandidates) {
            return new JsonResponse([
                'candidate_pks' => $this->drops->orderedSelectableDropIds()->values(),
                'target' => $target, 'request_id' => (string) Str::uuid(),
                'selection_mode' => 'candidate_ids',
            ], 200, ['Cache-Control' => 'private, no-store']);
        }
        $drops = $manual ? $this->drops->selectDropsByIds(array_map('intval', $data['drop_pks']))
            : ($countIds ? $this->drops->countedDropsByIds(array_map('intval', $data['count_pks']))
                : ($target > 0 ? $this->drops->selectDrops($target) : $this->drops->allDrops($page)));
        $total = (int) $drops->sum('Amount_Dropped');
        if ($manual && $total > 10000000) {
            throw \Illuminate\Validation\ValidationException::withMessages(['drop_pks' => 'Selected drops exceed the 10,000,000 phone export limit.']);
        }
        if ($manual) $target = $total;

        return new JsonResponse([
            'drops' => $drops->values(), 'target' => $target, 'total' => $total,
            'request_id' => (string) Str::uuid(), 'page' => $page,
            'has_more' => ! $manual && ! $countIds && $target === 0 && $this->drops->allDrops($page + 1)->isNotEmpty(),
            'selection_mode' => $manual ? 'manual' : ($countIds ? 'counted_ids' : ($target > 0 ? 'automatic' : 'browse')),
            'shortfall' => max(0, $target - $total),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function export(Request $request): BinaryFileResponse
    {
        // Reuse validation rules, but do not invoke the Blade request's Laravel-user gate.
        $data = Validator::make($request->all(), (new MailDropExportRequest)->rules())->validate();
        $this->ensureSchemaReady();
        set_time_limit(0);
        $export = isset($data['drop_pks'])
            ? $this->drops->prepareExport((int) $data['target'], $data['request_id'], array_map('intval', $data['drop_pks']))
            : $this->drops->prepareExport((int) $data['target'], $data['request_id']);
        $zip = ($export['format'] ?? 'csv') === 'zip';
        clearstatcache(true, $export['path']);
        $response = new BinaryFileResponse($export['path'], 200, [
            'Content-Type' => $zip ? 'application/zip' : 'text/csv; charset=UTF-8',
            'X-SMS-Count' => (string) $export['count'],
            'X-SMS-File-Count' => (string) ($export['part_count'] ?? 1),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->setContentDisposition('attachment', 'sms_export_'.now()->format('Ymd_His').($zip ? '.zip' : '.csv'));

        return $response->deleteFileAfterSend(true);
    }

    public function history(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), ['week' => ['required', 'date_format:Y-m-d']])->validate();
        $this->ensureSchemaReady();
        $week = Carbon::parse($data['week'])->startOfWeek(Carbon::MONDAY)->toDateString();
        $exports = $this->marketing->smsExports($week);
        $sources = DB::connection('sqlsrv')->table('TblSmsExportSources as s')
            ->leftJoin('TblMarketing as m', 'm.PK', '=', 's.Marketing_PK')
            ->whereIn('s.SMS_Export_PK', $exports->pluck('PK')->all())
            ->orderBy('s.Marketing_PK')
            ->get(['s.SMS_Export_PK', 's.Marketing_PK', 'm.Drop_Name', 's.SMS_Count'])
            ->groupBy('SMS_Export_PK');
        foreach ($exports as $export) {
            $export->sources = $sources->get($export->PK, collect())->map(fn ($source) => [
                'Marketing_PK' => $source->Marketing_PK, 'Drop_Name' => $source->Drop_Name,
                'SMS_Count' => $source->SMS_Count,
            ])->values();
        }

        return new JsonResponse(['week' => $week, 'exports' => $exports->values()], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function invoice(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), (new MarketingInvoiceRequest)->rules())->validate();
        $this->ensureSchemaReady();
        $this->marketing->allocateInvoice($data);

        return new JsonResponse([
            'ok' => true,
            'message' => 'Invoice saved. Mail and data costs are allocated across every tier in the selected drop; SMS costs are allocated across the export week.',
        ]);
    }

    protected function ensureSchemaReady(): void
    {
        $schema = DB::connection('sqlsrv')->getSchemaBuilder();
        if (! $schema->hasColumns('TblMarketing', ['SMS_Drops', 'SMS_Last_Export_Date'])
            || ! $schema->hasTable('TblSmsExports') || ! $schema->hasTable('TblSmsExportSources')) {
            throw new HttpException(503, 'SMS workflow is not ready: the reports-sms-migrations schema has not been applied.');
        }
    }
}
