<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ProspectListServiceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('prospect-list-builder.token');
        abort_unless(is_string($token) && $token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        $allowedTenantIds = config('prospect-list-builder.allowed_tenant_ids', []);
        abort_unless(in_array($request->header('X-Prospect-Tenant'), $allowedTenantIds, true), 403);
        abort_unless(Str::isUuid((string) $request->header('X-Prospect-Actor')), 403);

        return $next($request);
    }
}
