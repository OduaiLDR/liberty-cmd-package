<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Middleware;

use Closure;
use Cmd\Reports\Services\SmsAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Additional SMS action boundary after the host's authenticated CMD session. */
final class SmsPermission
{
    public function handle(Request $request, Closure $next, string $ability = 'auto'): Response
    {
        // The shared invoice handler also handles non-SMS marketing invoices.
        if (($ability === 'invoice' || str_ends_with(trim($request->decodedPath(), '/'), 'marketing-report/invoice'))
            && $request->input('kind') !== 'sms') return $next($request);
        $actor = $request->attributes->get('cmd_user');
        if (!SmsAccess::authenticated($actor)) throw new HttpException(401, 'Sign in again to continue.');
        if ($ability === 'auto') {
            // Laravel routes match the decoded path; authorization must use the
            // same representation (encoded suffixes cannot downgrade an action).
            $path = trim($request->decodedPath(), '/');
            if (!in_array($request->method(), ['GET', 'HEAD'], true)
                || str_ends_with($path, '/selection-status')
                || (str_ends_with($path, '/runs/availability') && $request->boolean('refresh'))) $ability = 'manage';
            elseif (str_ends_with($path, '/export-status') || str_ends_with($path, '/link')) $ability = 'download';
            else $ability = 'view';
        }
        $allowed = match ($ability) {
            'view' => SmsAccess::canView($actor),
            'manage', 'invoice' => SmsAccess::canManage($actor),
            'download' => SmsAccess::canDownload($actor),
            default => false,
        };
        if (!$allowed) throw new HttpException(403, 'Missing SMS '.$ability.' permission.');
        return $next($request);
    }
}
