<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\MailDropExportRequest;
use Cmd\Reports\Http\Requests\MarketingInvoiceRequest;
use Cmd\Reports\Jobs\BuildSmsExportJob;
use Cmd\Reports\Jobs\PlanSmsSelectionJob;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Cmd\Reports\Services\SmsExportArtifacts;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsSelectionProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
            'target' => ['nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'page' => ['nullable', 'integer', 'min:1'],
            'count_candidates' => ['nullable', 'boolean'],
            'count_pks' => ['sometimes', 'array', 'min:1', 'max:10'],
            'count_pks.*' => ['required', 'integer', 'min:1', 'distinct'],
            'drop_pks' => ['sometimes', 'array', 'min:1'],
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
        if ($manual) $target = $total;

        return new JsonResponse([
            'drops' => $drops->values(), 'target' => $target, 'total' => $total,
            'request_id' => (string) Str::uuid(), 'page' => $page,
            'has_more' => ! $manual && ! $countIds && $target === 0 && $this->drops->allDrops($page + 1)->isNotEmpty(),
            'selection_mode' => $manual ? 'manual' : ($countIds ? 'counted_ids' : ($target > 0 ? 'automatic' : 'browse')),
            'shortfall' => max(0, $target - $total),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function export(Request $request, ?SmsExportArtifacts $artifacts = null): JsonResponse
    {
        // Reuse validation rules, but do not invoke the Blade request's Laravel-user gate.
        $data = Validator::make($request->all(), (new MailDropExportRequest)->rules())->validate();
        $this->ensureSchemaReady();
        $this->ensureRequestSchemaReady();
        $artifacts ??= app(SmsExportArtifacts::class);
        $artifacts->assertConfigured();
        app(SmsExportQueue::class)->assertReady();
        $id = $data['request_id'];
        $actor = $this->actorEmail($request);
        $ids = isset($data['drop_pks']) ? array_map('intval', $data['drop_pks']) : null;
        $encoded = $ids === null ? null : json_encode($ids, JSON_THROW_ON_ERROR);
        $table = DB::connection('sqlsrv')->table('TblSmsExportRequests');
        $existing = (clone $table)->where('Request_ID', $id)->first();
        if ($existing !== null) {
            if (strcasecmp((string) $existing->Actor_Email, $actor) !== 0) throw new HttpException(404, 'SMS export request not found.');
            if ((int) $existing->Target !== (int) $data['target'] || $existing->Drop_PKs !== $encoded) {
                throw new HttpException(409, 'This request ID belongs to a different SMS selection. Refresh and try again.');
            }
            if ($existing->Status === 'failed') {
                if (DB::connection('sqlsrv')->table('TblSmsExports')->where('Request_ID', $id)->exists()) {
                    throw new HttpException(409, 'SMS tracking exists for this request. Check its status before retrying.');
                }
                $claimed = (clone $table)->where('Request_ID', $id)->where('Status', 'failed')->update([
                    'Status' => 'queued', 'Error' => null, 'Updated_At' => now()->toDateTimeString(),
                ]);
                if ($claimed === 1) {
                    try {
                        BuildSmsExportJob::dispatch($id);
                    } catch (\Throwable $error) {
                        (clone $table)->where('Request_ID', $id)->where('Status', 'queued')->update([
                            'Status' => 'failed', 'Error' => 'Unable to queue the SMS export.',
                            'Updated_At' => now()->toDateTimeString(),
                        ]);
                        throw $error;
                    }
                }
            }
        } else {
            $now = now()->toDateTimeString();
            $table->insert(['Request_ID' => $id, 'Actor_Email' => $actor, 'Status' => 'queued', 'Target' => $data['target'],
                'Drop_PKs' => $encoded, 'Created_At' => $now, 'Updated_At' => $now]);
            try {
                BuildSmsExportJob::dispatch($id);
            } catch (\Throwable $error) {
                (clone $table)->where('Request_ID', $id)->update(['Status' => 'failed',
                    'Error' => 'Unable to queue the SMS export.', 'Updated_At' => now()->toDateTimeString()]);
                throw $error;
            }
        }

        return new JsonResponse(['request_id' => $id, 'status' => (clone $table)->where('Request_ID', $id)->value('Status')], 202,
            ['Cache-Control' => 'private, no-store']);
    }

    public function exportStatus(Request $request, SmsExportArtifacts $artifacts): JsonResponse
    {
        $data = Validator::make($request->query(), ['request_id' => ['required', 'uuid']])->validate();
        // A status read uses the existing request row; schema is checked when queuing.
        $id = $data['request_id'];
        $row = DB::connection('sqlsrv')->table('TblSmsExportRequests')->where('Request_ID', $id)->first();
        if ($row === null || strcasecmp((string) $row->Actor_Email, $this->actorEmail($request)) !== 0) {
            throw new HttpException(404, 'SMS export request not found.');
        }

        if ($row->Status !== 'ready') {
            $tracked = DB::connection('sqlsrv')->table('TblSmsExports')->where('Request_ID', $id);
            if ($tracked->exists()) {
                try {
                    $recovered = $artifacts->recover($id, (int) $tracked->sum('SMS_Count'));
                    if ($recovered === null) throw new \RuntimeException('No matching durable artifact exists.');
                    DB::connection('sqlsrv')->table('TblSmsExportRequests')->where('Request_ID', $id)->update([
                        'Status' => 'ready', 'Artifact_Key' => $recovered['key'],
                        'Artifact_Format' => $recovered['format'], 'Artifact_Bytes' => $recovered['bytes'],
                        'SMS_Count' => $recovered['count'], 'Part_Count' => $recovered['part_count'],
                        'Error' => null, 'Updated_At' => now()->toDateTimeString(),
                    ]);
                    $row = DB::connection('sqlsrv')->table('TblSmsExportRequests')->where('Request_ID', $id)->first();
                } catch (\Throwable $error) {
                    return new JsonResponse(['request_id' => $id, 'status' => 'needs_reconciliation',
                        'error' => 'SMS tracking exists, but the export file could not be verified. Do not retry this request; contact an administrator.'],
                        200, ['Cache-Control' => 'private, no-store']);
                }
            }
        }

        $response = ['request_id' => $id, 'status' => $row->Status];
        if ($row->Status === 'ready') {
            try {
                $response['download_url'] = $artifacts->temporaryUrl($row->Artifact_Key, (int) $row->Artifact_Bytes);
                $response['count'] = (int) $row->SMS_Count;
                $response['part_count'] = (int) $row->Part_Count;
                $response['format'] = $row->Artifact_Format;
            } catch (\Throwable $error) {
                // Keep the recorded export visible; never regenerate it automatically.
                $response['status'] = 'needs_reconciliation';
                $response['error'] = 'SMS tracking was recorded, but the download is unavailable. Contact an administrator with this request ID.';
            }
        } elseif ($row->Status === 'failed') {
            $response['error'] = $row->Error ?: 'The SMS export failed.';
            if (DB::connection('sqlsrv')->table('TblSmsExports')->where('Request_ID', $id)->exists()) {
                $response['status'] = 'needs_reconciliation';
                $response['error'] = 'SMS tracking exists for this request. Do not export these drops again until reconciled.';
            }
        } elseif ($row->Status === 'running' && \Carbon\Carbon::parse($row->Updated_At)->lt(now()->subHours(3))) {
            $response['status'] = 'needs_reconciliation';
            $response['error'] = 'The export worker stopped or exceeded its time limit. Check tracking and the artifact before retrying.';
        }

        return new JsonResponse($response, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function selection(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'request_id' => ['required', 'uuid'],
            'target' => ['nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'drop_pks' => ['sometimes', 'array', 'min:1'],
            'drop_pks.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        if (isset($data['drop_pks']) === isset($data['target'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['target' => 'Enter a target or choose specific drops.']);
        }
        $this->ensureSchemaReady();
        $this->ensureSelectionSchemaReady();
        app(SmsExportQueue::class)->assertReady();

        $id = $data['request_id'];
        $actor = $this->actorEmail($request);
        $mode = isset($data['drop_pks']) ? 'manual' : 'automatic';
        $target = $mode === 'automatic' ? (int) $data['target'] : null;
        $ids = $mode === 'manual' ? json_encode(array_map('intval', $data['drop_pks']), JSON_THROW_ON_ERROR) : null;
        $table = DB::connection('sqlsrv')->table('TblSmsSelectionRequests');
        $existing = (clone $table)->where('Request_ID', $id)->first();
        if ($existing !== null) {
            if (strcasecmp((string) $existing->Actor_Email, $actor) !== 0) throw new HttpException(404, 'SMS selection request not found.');
            if ($existing->Selection_Mode !== $mode || (int) $existing->Target !== (int) $target || $existing->Drop_PKs !== $ids) {
                throw new HttpException(409, 'This request ID belongs to a different SMS selection.');
            }
            if ($existing->Status === 'failed') {
                $retryClaim = (clone $table)->where('Request_ID', $id)->where('Status', 'failed')
                    ->where('Updated_At', $existing->Updated_At);
                $existing->Result === null ? $retryClaim->whereNull('Result') : $retryClaim->where('Result', $existing->Result);
                $claimed = $retryClaim->update([
                    'Status' => 'queued', 'Error' => null, 'Updated_At' => now()->toDateTimeString(),
                ]);
                if ($claimed === 1) {
                    try {
                        app(SmsSelectionProgress::class)->forget($id);
                        $saved = $existing->Result ? json_decode($existing->Result, true, 512, JSON_THROW_ON_ERROR) : [];
                        PlanSmsSelectionJob::dispatch($id, (int) ($saved['checkpoint']['cursor'] ?? 0));
                    } catch (\Throwable $error) {
                        (clone $table)->where('Request_ID', $id)->where('Status', 'queued')->update([
                            'Status' => 'failed', 'Error' => 'Unable to queue SMS selection.',
                            'Updated_At' => now()->toDateTimeString(),
                        ]);
                        throw $error;
                    }
                }
            }
        } else {
            $now = now()->toDateTimeString();
            $table->insert(['Request_ID' => $id, 'Actor_Email' => $actor, 'Status' => 'queued',
                'Selection_Mode' => $mode, 'Target' => $target, 'Drop_PKs' => $ids,
                'Created_At' => $now, 'Updated_At' => $now]);
            try {
                app(SmsSelectionProgress::class)->forget($id);
                PlanSmsSelectionJob::dispatch($id);
            } catch (\Throwable $error) {
                (clone $table)->where('Request_ID', $id)->update(['Status' => 'failed', 'Error' => 'Unable to queue SMS selection.',
                    'Updated_At' => now()->toDateTimeString()]);
                throw $error;
            }
        }

        return new JsonResponse(['request_id' => $id, 'status' => (clone $table)->where('Request_ID', $id)->value('Status')], 202,
            ['Cache-Control' => 'private, no-store']);
    }

    public function selectionStatus(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), ['request_id' => ['required', 'uuid']])->validate();
        $actor = $this->actorEmail($request);
        $snapshot = app(SmsSelectionProgress::class)->read($data['request_id'], $actor);
        if ($snapshot !== null) {
            $heartbeat = $snapshot['progress']['heartbeat_at'] ?? null;
            if (! in_array($snapshot['status'] ?? '', ['queued', 'running'], true) || ($heartbeat !== null && Carbon::parse($heartbeat)->gte(now()->subHours(3)))) {
                return new JsonResponse($snapshot, 200, ['Cache-Control' => 'private, no-store']);
            }
        }
        // Polling needs a single request lookup, not schema metadata on every poll.
        $row = DB::connection('sqlsrv')->table('TblSmsSelectionRequests')->where('Request_ID', $data['request_id'])->first();
        if ($row === null || strcasecmp((string) $row->Actor_Email, $actor) !== 0) {
            throw new HttpException(404, 'SMS selection request not found.');
        }
        $result = ['request_id' => $data['request_id'], 'status' => $row->Status, 'selection_mode' => $row->Selection_Mode];
        if ($row->Status === 'ready') {
            $result += json_decode($row->Result, true, 512, JSON_THROW_ON_ERROR);
        } elseif ($row->Status === 'failed') {
            $result['error'] = $row->Error ?: 'SMS selection failed.';
        } elseif (in_array($row->Status, ['queued', 'running'], true) && \Carbon\Carbon::parse($row->Updated_At)->lt(now()->subHours(3))) {
            $changed = DB::connection('sqlsrv')->table('TblSmsSelectionRequests')->where('Request_ID', $row->Request_ID)
                ->where('Status', $row->Status)->where('Updated_At', $row->Updated_At)
                ->update(['Status' => 'failed', 'Error' => 'The selection worker stopped or exceeded its time limit. Resume the saved batches.', 'Updated_At' => now()->toDateTimeString()]);
            if ($changed === 1) {
                app(SmsSelectionProgress::class)->forget($row->Request_ID);
                $result['status'] = 'failed';
                $result['error'] = 'The selection worker stopped or exceeded its time limit. Resume the saved batches.';
            }
        }
        if ($row->Status !== 'ready' && $row->Result !== null) {
            $partial = json_decode($row->Result, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($partial['progress'] ?? null)) $result['progress'] = $partial['progress'];
            $result['can_resume'] = is_array($partial['checkpoint'] ?? null);
        }

        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
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

    protected function ensureRequestSchemaReady(): void
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblSmsExportRequests')) {
            throw new HttpException(503, 'SMS workflow is not ready: publish and apply the new reports-sms-migrations request schema.');
        }
    }

    protected function ensureSelectionSchemaReady(): void
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblSmsSelectionRequests')) {
            throw new HttpException(503, 'SMS workflow is not ready: publish and apply the new reports-sms-migrations selection schema.');
        }
    }

    protected function actorEmail(Request $request): string
    {
        $user = $request->attributes->get('cmd_user');
        $email = is_array($user) ? strtolower(trim((string) ($user['email'] ?? ''))) : '';
        if ($email === '') throw new HttpException(401, 'An authenticated CMD user is required for SMS exports.');

        return $email;
    }
}
