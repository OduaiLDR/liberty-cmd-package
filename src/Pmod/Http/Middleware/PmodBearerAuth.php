<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PmodBearerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.pmod.internal_token');

        if (empty($expectedToken)) {
            return response()->json([
                'message' => 'PMOD internal token not configured.',
            ], 503);
        }

        if (!hash_equals($expectedToken, (string) $token)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}