<?php

declare(strict_types=1);

use Cmd\Reports\Http\Middleware\SmsFreshSession;
use Cmd\Reports\Http\Middleware\SmsPermission;
use Cmd\Reports\Services\SmsAccess;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Pure grants and request routing only; no app bootstrap, .env, DB or network. */
final class SmsAccessTest extends TestCase
{
    private function actor(array $grants = [], array $extra = []): array
    {
        return $extra + ['email' => 'synthetic@example.invalid', 'permissionIds' => $grants];
    }

    public function test_explicit_grant_implications_and_no_role_name_authority(): void
    {
        foreach ([
            [[], [], false, false, false],
            [[SmsAccess::VIEW], [], true, false, false],
            [[SmsAccess::DOWNLOAD], [], true, false, true],
            [[SmsAccess::MANAGE], [], true, true, true],
            [['*'], [], true, true, true],
            [[], ['siteAdmin' => true], true, true, true],
            [[], ['role' => 'Admin', 'roleName' => 'Exec Admin'], false, false, false],
            [[], ['siteAdmin' => 'false'], false, false, false],
            [[], ['brandAccess' => [['brand' => 'LDR', 'permissionIds' => [SmsAccess::MANAGE]]]], true, true, true],
        ] as [$grants, $extra, $view, $manage, $download]) {
            $actor = $this->actor($grants, $extra);
            self::assertSame($view, SmsAccess::canView($actor));
            self::assertSame($manage, SmsAccess::canManage($actor));
            self::assertSame($download, SmsAccess::canDownload($actor));
        }
        foreach ([null, [], ['siteAdmin' => true], $this->actor(['*'], ['anonymous' => true])] as $actor) {
            self::assertFalse(SmsAccess::canView($actor));
            self::assertFalse(SmsAccess::canManage($actor));
            self::assertFalse(SmsAccess::canDownload($actor));
        }
    }

    private function request(string $suffix, string $method, array $input = [], mixed $actor = null): Request
    {
        $request = Request::create('/api/cmd/'.$suffix, $method, $input);
        if ($actor !== null) $request->attributes->set('cmd_user', $actor);
        return $request;
    }

    private function permissionStatus(Request $request, string $ability = 'auto'): int
    {
        try { return (new SmsPermission)->handle($request, fn () => new Response('allowed', 204), $ability)->getStatusCode(); }
        catch (HttpException $error) { return $error->getStatusCode(); }
    }

    public function test_route_action_matrix_denies_before_business_handlers(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $rows = [
            ['mail-drop-export-report/preview', 'GET', [], 'view'],
            ['mail-drop-export-report/selection-status', 'GET', [], 'manage'],
            ['mail-drop-export-report/export-status', 'GET', [], 'download'],
            ['mail-drop-export-report/export-%73tatus', 'GET', [], 'download'],
            ['mail-drop-export-report/workflow', 'GET', [], 'view'],
            ['mail-drop-export-report/runs', 'GET', [], 'view'],
            ['mail-drop-export-report/runs/history', 'GET', [], 'view'],
            ['mail-drop-export-report/runs/availability', 'GET', [], 'view'],
            ['mail-drop-export-report/runs/availability', 'GET', ['refresh' => 1], 'manage'],
            ["mail-drop-export-report/runs/$id", 'GET', [], 'view'],
            ["mail-drop-export-report/runs/$id/details", 'GET', [], 'view'],
            ["mail-drop-export-report/runs/$id/link", 'GET', [], 'download'],
            ["mail-drop-export-report/runs/$id/%6cink", 'GET', [], 'download'],
            ['mail-drop-export-report/runs/%61vailability', 'GET', ['refresh' => 1], 'manage'],
            ['mail-drop-export-report/tu-source', 'GET', [], 'view'],
            ["mail-drop-export-report/tu-source/uploads/$id", 'GET', [], 'view'],
            ['marketing-report/sms-history', 'GET', [], 'view'],
            ['marketing-report/invoice', 'POST', ['kind' => 'sms'], 'manage'],
        ];
        foreach (['export', 'selection', 'runs', 'workflow/load', "workflow/load/$id/retry", 'workflow/feedback', 'workflow/enrichment', "workflow/enrichment/$id/retry", 'tu-source/uploads', "tu-source/uploads/$id/finalize"] as $path) {
            $rows[] = ['mail-drop-export-report/'.$path, 'POST', [], 'manage'];
        }
        foreach (['file', 'sent', 'approve', 'review-only', 'not-sent', 'discard', 'retry'] as $action) {
            $rows[] = ["mail-drop-export-report/runs/$id/$action", 'POST', [], 'manage'];
        }
        foreach ($rows as [$path, $method, $input, $need]) {
            foreach ([SmsAccess::VIEW, SmsAccess::DOWNLOAD, SmsAccess::MANAGE] as $grant) {
                $allowed = $grant === SmsAccess::MANAGE || $need === 'view' || ($need === 'download' && $grant === SmsAccess::DOWNLOAD);
                self::assertSame($allowed ? 204 : 403, $this->permissionStatus($this->request($path, $method, $input, $this->actor([$grant]))), "$method $path with $grant");
            }
            self::assertSame(401, $this->permissionStatus($this->request($path, $method, $input)));
        }
        foreach (['mail', 'data'] as $kind) self::assertSame(204, $this->permissionStatus($this->request('marketing-report/invoice', 'POST', ['kind' => $kind])));
        self::assertSame(401, $this->permissionStatus($this->request('mail-drop-export-report/runs', 'POST', ['siteAdmin' => true, 'permissionIds' => ['*'], 'email' => 'forged@example.invalid'])));
        self::assertSame(403, $this->permissionStatus($this->request('mail-drop-export-report/runs', 'GET', [], $this->actor(['*'])), 'unknown'));
    }

    public function test_download_marker_and_permission_are_independent_requirements(): void
    {
        foreach (['mail-drop-export-report/download', 'mail-drop-export-report/runs/id/download', 'mail-drop-export-report/runs/id/link', 'mail-drop-export-report/export-status', 'mail-drop-export-report/runs/id/%6cink'] as $path) {
            $request = $this->request($path, 'GET', [], $this->actor([SmsAccess::VIEW]));
            (new SmsFreshSession)->handle($request, fn () => new Response);
            self::assertTrue($request->attributes->get('cmd_session_fresh'));
            self::assertSame(403, $this->permissionStatus($request, 'download'));
            $request->attributes->set('cmd_user', $this->actor([SmsAccess::DOWNLOAD]));
            self::assertSame(204, $this->permissionStatus($request, 'download'));
        }
        $normal = $this->request('mail-drop-export-report/runs', 'GET');
        (new SmsFreshSession)->handle($normal, fn () => new Response);
        self::assertNull($normal->attributes->get('cmd_session_fresh'));
    }
}
