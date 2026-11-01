<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Services\SmsDownloadContinuation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Stream directly from runner using the existing immutable-file handler, after fresh authorization. */
final class SmsRunDownloadContinuationController extends Controller
{
    public function __construct(private SmsDownloadContinuation $downloads, private SmsRunController $runs) {}

    public function __invoke(Request $request, string $requestId): BinaryFileResponse
    {
        $this->downloads->assertActor($request, $requestId);
        $response = $this->runs->download($requestId);
        $this->downloads->assertArtifact($request, $requestId, $response->getFile()->getPathname());
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
