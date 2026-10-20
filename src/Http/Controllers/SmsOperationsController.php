<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Jobs\SmsProspectMaintenanceJob;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsFeedbackService;
use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Repositories\SmsEnrichmentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Maintenance stays separate from campaign selection: no implicit imports or sends on GET. */
class SmsOperationsController extends Controller
{
    public function overview(): JsonResponse
    {
        $this->ready();
        $db = DB::connection('sqlsrv');
        $jobs = $db->table('TblSMSMaintenanceJobs')->orderByDesc('PK')->limit(10)
            ->get(['Request_ID', 'Status', 'Since_Date', 'Drops_Total', 'Drops_Done', 'Added', 'Filled', 'Current_Drop', 'Error', 'Created_By', 'Created_At', 'Updated_At']);
        $feedback = $db->table('TblSMSFeedbackImports')->groupBy('Request_ID', 'Created_By')
            ->selectRaw('Request_ID, Created_By, MAX(Created_At) AS Created_At, SUM(Records) AS Records, SUM(Matched) AS Matched, SUM(Updated) AS Updated')
            ->orderByDesc('Created_At')->limit(10)->get();
        $enrichment = $db->getSchemaBuilder()->hasTable('TblSMSEnrichmentBatches') ? app(SmsEnrichmentRepository::class)->recent() : [];
        $transport = class_exists(\App\Services\SmsProspectIntakeService::class) ? app(\App\Services\SmsProspectIntakeService::class)->recent() : [];
        return $this->json(['loads' => $jobs, 'feedback' => $feedback, 'enrichment' => $enrichment, 'submissions' => $transport,
            'enrichment_enabled' => (bool) config('sms-exports.prospect_enrichment_enabled', false)]);
    }

    public function enrichment(Request $request): JsonResponse
    {
        $this->ready();
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'since' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'confirmed_cost' => ['required', 'accepted']]);
        if (! config('sms-exports.prospect_enrichment_enabled', false) || ! class_exists(\App\Services\SmsProspectIntakeService::class)) throw new HttpException(503, 'Prospect enrichment is not enabled on this CMD server. Apply its migrations and enable the configured workflow first.');
        return $this->json(app(\App\Services\SmsProspectIntakeService::class)->start($data['request_id'], $data['since'], $this->actor($request)), 202);
    }

    public function retryEnrichment(string $requestId): JsonResponse
    {
        $this->ready();
        if (! class_exists(\App\Services\SmsProspectIntakeService::class)) throw new HttpException(503, 'Prospect enrichment transport is not installed.');
        return $this->json(app(\App\Services\SmsProspectIntakeService::class)->retry($requestId), 202);
    }

    public function load(Request $request): JsonResponse
    {
        $this->ready();
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'since' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        return SmsWorkflowLock::run('maintenance-start', function () use ($request, $data): JsonResponse {
            $db = DB::connection('sqlsrv');
            $table = $db->table('TblSMSMaintenanceJobs');
            $existing = (clone $table)->where('Request_ID', $data['request_id'])->first();
            if ($existing) {
                if ($existing->Since_Date !== $data['since']) throw new HttpException(409, 'This request ID already belongs to another load.');
                return $this->json($existing);
            }
            if ((clone $table)->whereIn('Status', ['queued', 'running'])->exists()) throw new HttpException(409, 'A prospect load is already running. Refresh its progress.');
            app(SmsExportQueue::class)->assertReady();
            $table->insert(['Request_ID' => $data['request_id'], 'Since_Date' => $data['since'], 'Status' => 'queued',
                'Created_By' => $this->actor($request), 'Created_At' => now()->toDateTimeString(), 'Updated_At' => now()->toDateTimeString()]);
            $this->dispatch($data['request_id']);
            return $this->json((clone $table)->where('Request_ID', $data['request_id'])->first(), 202);
        });
    }

    public function retryLoad(string $requestId): JsonResponse
    {
        $this->ready();
        return SmsWorkflowLock::run('maintenance-start', function () use ($requestId): JsonResponse {
            $table = DB::connection('sqlsrv')->table('TblSMSMaintenanceJobs');
            if ((clone $table)->whereIn('Status', ['queued', 'running'])->exists()) throw new HttpException(409, 'A prospect load is already running.');
            if ((clone $table)->where('Request_ID', $requestId)->where('Status', 'failed')->update(['Status' => 'queued', 'Error' => null, 'Updated_At' => now()->toDateTimeString()]) !== 1) {
                throw new HttpException(409, 'Only a failed load can be retried.');
            }
            $this->dispatch($requestId);
            return $this->json((clone $table)->where('Request_ID', $requestId)->first(), 202);
        });
    }

    public function feedback(Request $request, SmsFeedbackService $feedback): JsonResponse
    {
        $this->ready();
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'chunk' => ['required', 'integer', 'min:0'],
            'records' => ['required', 'array', 'min:1', 'max:500'], 'records.*.phone' => ['required', 'string', 'max:50'],
            'records.*.status' => ['required', 'in:opt_out,invalid_phone']]);
        return $this->json($feedback->import($data['request_id'], $data['chunk'], $data['records'], $this->actor($request)));
    }

    protected function ready(): void
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblSMSMaintenanceJobs')) {
            throw new HttpException(503, 'Apply the 19 Oct SMS workflow migration before using prospect tools.');
        }
    }

    protected function actor(Request $request): string
    {
        $user = $request->attributes->get('cmd_user');
        $email = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
        if ($email === '') throw new HttpException(401, 'Sign in again to continue.');
        return strtolower($email);
    }

    protected function json(mixed $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'private, no-store']);
    }

    private function dispatch(string $id): void
    {
        try {
            SmsProspectMaintenanceJob::dispatch($id);
        } catch (Throwable $error) {
            DB::connection('sqlsrv')->table('TblSMSMaintenanceJobs')->where('Request_ID', $id)
                ->update(['Status' => 'failed', 'Error' => 'The load could not be queued.', 'Updated_At' => now()->toDateTimeString()]);
            throw $error;
        }
    }
}
