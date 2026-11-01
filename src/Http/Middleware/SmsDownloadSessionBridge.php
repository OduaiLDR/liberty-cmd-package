<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Middleware;

use Closure;
use Cmd\Reports\Services\SmsDownloadContinuation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One request only: resolve the opaque grant, then let the existing fresh session guards authenticate it. */
final class SmsDownloadSessionBridge
{
    public function __construct(private SmsDownloadContinuation $downloads) {}

    public function handle(Request $request, Closure $next): Response
    {
        $receipt = $this->downloads->consume($request, (string) $request->route('requestId'));
        $originalCookie = $request->headers->get('Cookie');
        $request->headers->set('Cookie', $receipt['cookie']);
        // Do not retain the upstream credential in request attributes or expose it to the file controller.
        unset($receipt['cookie']);
        $request->attributes->set(SmsDownloadContinuation::ATTRIBUTE, $receipt);
        $request->attributes->set('cmd_session_fresh', true);
        $request->attributes->remove('cmd_user');
        try { return $next($request); }
        finally {
            if ($originalCookie === null) $request->headers->remove('Cookie');
            else $request->headers->set('Cookie', $originalCookie);
            $request->attributes->remove(SmsDownloadContinuation::ATTRIBUTE);
        }
    }
}
