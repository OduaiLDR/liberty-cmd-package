<?php

declare(strict_types=1);

use Cmd\Reports\Http\Middleware\SmsFreshSession;
use Cmd\Reports\Http\Middleware\SmsPermission;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** No host application bootstrap: all HTTP is fake and cache is memory-only. */
final class SmsSessionAuthorizationTest extends TestCase
{
    private Container $previous;
    private Container $container;
    private UrlGenerator $urls;

    protected function setUp(): void
    {
        parent::setUp();
        // Load definitions only; never require cmd-runner/bootstrap/app.php or .env.
        foreach (['app/Http/Middleware/VerifyCmdSession.php', 'app/Reports/Shared/ReportPolicy.php'] as $file) require_once dirname(__DIR__, 3).'/cmd-runner/'.$file;
        $this->previous = Container::getInstance();
        $this->container = new class extends Container {
            public function environment(...$values): string|bool { return $values === [] ? 'testing' : false; }
            public function abort($code, $message = '', array $headers = []): never { throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers); }
        };
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['services' => ['cmd' => ['auth_enabled' => true,
            'auth_validate_url' => 'https://identity.fixture.invalid', 'auth_cache_ttl' => 60]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
        $this->container->instance('cache', new CacheManager($this->container));
        $this->container->instance(Factory::class, new Factory);
        $routes = new RouteCollection;
        $routes->add((new Route(['GET'], 'api/cmd/mail-drop-export-report/runs/{requestId}/download', fn () => null))->name('test.sms.download'));
        $this->urls = new UrlGenerator($routes, Request::create('https://cmd.fixture.invalid'));
        $this->urls->setKeyResolver(fn () => 'isolated-test-signing-key');
        $this->container->instance('url', $this->urls);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->container);
        // Same macro as Laravel's FoundationServiceProvider, without booting an app.
        Request::macro('hasValidSignatureWhileIgnoring', function ($ignore = [], $absolute = true) {
            return URL::hasValidSignature($this, $absolute, $ignore);
        });
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
        parent::tearDown();
    }

    private function signed(): string
    {
        return 'https://cmd.fixture.invalid'.$this->urls->temporarySignedRoute('test.sms.download', new DateTimeImmutable('+15 minutes'),
            ['requestId' => '11111111-1111-4111-8111-111111111111'], false);
    }

    private function pipeline(Request $request): int
    {
        try {
            return (new ValidateSignature)->handle($request, fn ($r) => (new SmsFreshSession)->handle($r,
                fn ($r) => (new \App\Http\Middleware\VerifyCmdSession)->handle($r,
                    fn ($r) => (new SmsPermission)->handle($r, fn () => new Response('file-handler-reached', 204), 'download'))), 'relative')->getStatusCode();
        } catch (HttpExceptionInterface $error) { return $error->getStatusCode(); }
    }

    public function test_signature_and_current_session_permission_are_both_required(): void
    {
        $identity = ['email' => 'agent@example.invalid', 'permissionIds' => ['cmd_sms_download']];
        Http::fake(['identity.fixture.invalid/*' => fn () => Http::response($identity)]);
        self::assertSame(403, $this->pipeline(Request::create('https://cmd.fixture.invalid/api/cmd/mail-drop-export-report/runs/11111111-1111-4111-8111-111111111111/download')));
        $expired = 'https://cmd.fixture.invalid'.$this->urls->temporarySignedRoute('test.sms.download', new DateTimeImmutable('-1 minute'),
            ['requestId' => '11111111-1111-4111-8111-111111111111'], false);
        self::assertSame(403, $this->pipeline(Request::create($expired)));
        Http::assertNothingSent();
        self::assertSame(204, $this->pipeline(Request::create($this->signed())));
        self::assertSame(403, $this->pipeline(Request::create($this->signed().'&extra=changed')));
        Http::swap(new Factory); Http::preventStrayRequests();
        Http::fake(['identity.fixture.invalid/*' => Http::response(['email' => 'agent@example.invalid', 'permissionIds' => ['cmd_report_mail_drop_export']])]);
        self::assertSame(403, $this->pipeline(Request::create($this->signed())));
        Http::swap(new Factory); Http::preventStrayRequests();
        Http::fake(['identity.fixture.invalid/*' => Http::response([], 401)]);
        self::assertSame(401, $this->pipeline(Request::create($this->signed())));
    }

    public function test_download_revalidates_even_when_same_cookie_has_cached_download_grant(): void
    {
        $cookie = 'fixture-session=unchanged';
        Cache::put('cmd_session:'.hash('sha256', $cookie), ['user' => ['email' => 'agent@example.invalid', 'permissionIds' => ['cmd_sms_download']]], 60);
        Http::fake(['identity.fixture.invalid/*' => Http::response(['email' => 'agent@example.invalid', 'permissionIds' => ['cmd_report_mail_drop_export']])]);
        $request = Request::create($this->signed()); $request->headers->set('Cookie', $cookie);
        self::assertSame(403, $this->pipeline($request));
        Http::assertSentCount(1);
    }

    public function test_host_report_view_accepts_implied_sms_view_without_broadening_other_reports(): void
    {
        $policy = new class extends \App\Reports\Shared\ReportPolicy { public function slug(): string { return 'mail-drop-export'; } };
        foreach (['cmd_report_mail_drop_export', 'cmd_sms_manage', 'cmd_sms_download'] as $grant) {
            $actor = ['email' => 'actor@example.invalid', 'permissionIds' => [$grant]];
            self::assertTrue($policy->view($actor));
            self::assertFalse(\App\Reports\Shared\ReportPolicy::userHasPermission($actor, 'cmd_report_marketing'));
        }
        self::assertFalse($policy->view(['email' => 'actor@example.invalid', 'role' => 'Admin']));
    }

    public function test_actual_runner_tu_routes_retain_action_guard_and_expected_methods(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/cmd-runner/routes/api.php');
        $start = strpos($source, "Route::prefix('cmd/mail-drop-export-report/tu-source')");
        $end = strpos($source, "Route::post('/cmd/commission-report-send'", $start);
        self::assertNotFalse($start); self::assertNotFalse($end);
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($this->container), $this->container);
        $this->container->instance('router', $router); Facade::clearResolvedInstance('router');
        $code = str_replace(['Route::', 'TuSourceIntakeController::'], ['\\Illuminate\\Support\\Facades\\Route::', '\\App\\Http\\Controllers\\Api\\TuSourceIntakeController::'], substr($source, $start, $end - $start));
        eval($code);
        foreach ([['GET', ''], ['POST', '/uploads'], ['POST', '/uploads/11111111-1111-4111-8111-111111111111/finalize'],
            ['GET', '/uploads/11111111-1111-4111-8111-111111111111']] as [$method, $suffix]) {
            $route = $router->getRoutes()->match(Request::create('/cmd/mail-drop-export-report/tu-source'.$suffix, $method));
            self::assertSame(['cmd.session', 'cmd.policy', SmsPermission::class], $route->gatherMiddleware());
        }
    }
}
