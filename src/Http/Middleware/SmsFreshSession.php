<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signed downloads must revalidate current access, not reuse a cached actor. */
final class SmsFreshSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->decodedPath(), '/');
        if (str_ends_with($path, '/download') || str_ends_with($path, '/export-status') || str_ends_with($path, '/link')
            || str_ends_with($path, '/cancel-selection')) {
            $request->attributes->set('cmd_session_fresh', true);
        }
        return $next($request);
    }
}
