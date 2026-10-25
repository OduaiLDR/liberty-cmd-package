<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Mail Drop Export page: person-level SMS runs. Authorization comes from the CMD host's session
 * and report-policy middleware (route names cmd.mail_drop_export.*); the file download is a
 * short-lived signed link instead.
 */
class SmsRunController extends Controller
{
    public function __construct(protected SmsRunService $runs) {}

    /** The open run (if any) and the first closed-history page. */
    public function index(): JsonResponse
    {
        $this->ensureSchemaReady();
        $overview = $this->runs->overview();
        if ($overview['open'] && in_array($overview['open']->status, ['file_ready', 'sending', 'sent', 'approving', 'approved'], true)) {
            $overview['open']->download_ready = true;
        }

        return $this->json($overview);
    }

    /** Read older closed runs under the same authenticated report policy as index(). */
    public function history(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'before_id' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ])->validate();
        $this->ensureSchemaReady();
        return $this->json($this->runs->history(isset($data['before_id']) ? (int) $data['before_id'] : null, (int) ($data['limit'] ?? 10)));
    }

    /** Return a cached aggregate or queue one; never run the full count in HTTP. */
    public function availability(Request $request, SmsAvailabilityService $availability): JsonResponse
    {
        $data = Validator::make($request->query(), ['refresh' => ['sometimes', 'boolean']])->validate();
        $this->ensureSchemaReady();
        $demo = \Cmd\Reports\Services\SmsDemoGuard::enabled();
        $snapshot = $availability->snapshot((bool) ($data['refresh'] ?? false));
        return $this->json($snapshot + ['demo' => $demo], $snapshot['state'] === 'pending' ? 202 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'request_id' => ['required', 'uuid'],
            'target' => ['required', 'integer', 'min:1', 'max:'.SmsRunService::MAX_TARGET],
            'csv_format' => ['required_with:rows_per_file,tiers,tier_selection_policy,options_version,send_date,filename_version', 'sometimes', 'string', 'in:'.SmsRunOptions::CSV_FORMAT],
            'options_version' => ['sometimes', 'string', 'in:'.SmsRunOptions::PLANNED_DATE_VERSION],
            'filename_version' => ['sometimes', 'string', 'in:'.SmsRunOptions::DROP_RANGE_FILENAME_VERSION.','.SmsRunOptions::APPROVAL_FILENAME_VERSION],
            'send_date' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'tier_selection_policy' => ['sometimes', 'string', 'in:'.implode(',', SmsRunOptions::TIER_SELECTION_POLICIES)],
            'rows_per_file' => ['sometimes', 'integer', 'min:1', 'max:'.SmsRunService::MAX_TARGET],
            'tiers' => ['sometimes', 'array', 'min:1'],
            'tiers.*' => ['required', 'string', 'distinct:strict', 'in:'.implode(',', SmsRunOptions::TIERS)],
        ], [
            'target.required' => 'Enter how many records you want.',
            'target.integer' => 'Enter a whole number of records.',
            'target.min' => 'Enter at least 1 record.',
            'target.max' => 'Enter at most 10,000,000 records.',
        ])->validate();
        $this->ensureSchemaReady();

        if (! isset($data['csv_format']) && (array_key_exists('rows_per_file', $data) || array_key_exists('tiers', $data) || array_key_exists('tier_selection_policy', $data)
            || array_key_exists('options_version', $data) || array_key_exists('send_date', $data) || array_key_exists('filename_version', $data))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['csv_format' => 'Choose the explicit SMS file format when supplying file options.']);
        }
        $options = isset($data['csv_format']) ? [
            'csv_format' => $data['csv_format'],
            'rows_per_file' => isset($data['rows_per_file']) ? (int) $data['rows_per_file'] : null,
            'tiers' => $data['tiers'] ?? null,
        ] : null;
        if ($options !== null && isset($data['tier_selection_policy'])) $options['tier_selection_policy'] = $data['tier_selection_policy'];
        foreach (['options_version', 'send_date', 'filename_version'] as $key) if ($options !== null && isset($data[$key])) $options[$key] = $data[$key];
        // Keep old callers' three-argument contract intact.
        $id = strtolower($data['request_id']);
        $actor = $this->actor($request);
        return $this->json($options === null
            ? $this->runs->start($id, (int) $data['target'], $actor)
            : $this->runs->start($id, (int) $data['target'], $actor, $options), 202);
    }

    public function show(string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();

        return $this->json($this->runs->show($requestId));
    }

    public function file(string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json($this->runs->requestFile($requestId), 202);
    }

    public function cancelSelection(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json(app(\Cmd\Reports\Services\SmsSelectionCancellation::class)
            ->requestCancel($requestId, $this->actor($request)), 202);
    }

    public function link(Request $request, string $requestId): JsonResponse
    {
        $link = ['download_url' => $this->runs->downloadUrl($requestId)];
        if (config('sms-exports.download_continuation_enabled', false) === true) {
            $link['download_request'] = app(\Cmd\Reports\Services\SmsDownloadContinuation::class)->issue($request, $requestId);
        }
        return $this->json($link);
    }

    public function sent(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        $request->validate(['confirmed_sent' => ['required', 'accepted']]);
        return $this->json($this->runs->requestSent($requestId, $this->actor($request)), 202);
    }

    public function approve(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        Validator::make($request->all(), ['confirmed_approval' => ['required', 'accepted']])->validate();
        return $this->json($this->runs->requestApprove($requestId, $this->actor($request)), 202);
    }

    public function details(string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json($this->runs->details($requestId));
    }

    public function reviewOnly(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json($this->runs->reviewOnly($requestId, $this->actor($request)));
    }

    public function notSent(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        $data = $request->validate(['confirmed_not_sent' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        return $this->json($this->runs->requestNotSent($requestId, $this->actor($request), $data['reason']), 202);
    }

    public function discard(string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json($this->runs->discard($requestId));
    }

    public function retry(string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        return $this->json($this->runs->retry($requestId), 202);
    }

    /** Reached only through the signed link from link(). */
    public function download(string $requestId): BinaryFileResponse
    {
        $path = $this->runs->filePath($requestId);
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => str_ends_with(strtolower($path), '.zip') ? 'application/zip' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));

        return $response;
    }

    protected function ensureSchemaReady(): void
    {
        $schema = DB::connection('sqlsrv')->getSchemaBuilder();
        if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasTable('TblProspects') || ! $schema->hasTable('TblProspectsExport')
            || ! $schema->hasTable('TblSMSHistory') || ! $schema->hasTable('TblSMSAttemptAudit')
            || ! $schema->hasColumn('TblSMSRuns', 'Progress_Step')) {
            throw new HttpException(503, 'Apply the 19 Oct SMS workflow migration on this server before using the new page.');
        }
        if (\Cmd\Reports\Services\SmsRecordPolicy::enabled()) \Cmd\Reports\Services\SmsRecordPolicy::assertReady();
    }

    protected function actor(Request $request): string
    {
        $user = $request->attributes->get('cmd_user');
        $email = is_array($user) ? strtolower(trim((string) ($user['email'] ?? ''))) : '';
        if ($email === '') throw new HttpException(401, 'Sign in again to continue.');

        return $email;
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
