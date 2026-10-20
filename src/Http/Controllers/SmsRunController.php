<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsRunService;
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

    /** The open run (if any) and the last ten finished runs. */
    public function index(): JsonResponse
    {
        $this->ensureSchemaReady();
        $overview = $this->runs->overview();
        if ($overview['open'] && in_array($overview['open']->status, ['file_ready', 'sending', 'sent'], true)) {
            $overview['open']->download_ready = true;
        }

        return $this->json($overview);
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
        ], [
            'target.required' => 'Enter how many records you want.',
            'target.integer' => 'Enter a whole number of records.',
            'target.min' => 'Enter at least 1 record.',
            'target.max' => 'Enter at most 10,000,000 records.',
        ])->validate();
        $this->ensureSchemaReady();

        return $this->json($this->runs->start(strtolower($data['request_id']), (int) $data['target'], $this->actor($request)), 202);
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

    public function link(string $requestId): JsonResponse
    {
        return $this->json(['download_url' => $this->runs->downloadUrl($requestId)]);
    }

    public function sent(Request $request, string $requestId): JsonResponse
    {
        $this->ensureSchemaReady();
        $request->validate(['confirmed_sent' => ['required', 'accepted']]);
        return $this->json($this->runs->requestSent($requestId, $this->actor($request)), 202);
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
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
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
