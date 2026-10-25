<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Http\Controllers\SmsRunController;
use Cmd\Reports\Http\Controllers\SmsRunDownloadContinuationController;
use Cmd\Reports\Http\Middleware\SmsDownloadSessionBridge;
use Cmd\Reports\Http\Middleware\SmsFreshSession;
use Cmd\Reports\Http\Middleware\SmsPermission;
use Cmd\Reports\Services\SmsDownloadContinuation;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use ZipArchive;

/** No application bootstrap, real HTTP, provider, business database or .env access. */
final class SmsDownloadContinuationTest extends TestCase
{
    private const ID = '11111111-2222-4333-8444-555555555555';
    private const OTHER = '21111111-2222-4333-8444-555555555555';
    private const COOKIE = 'fixture_session=synthetic-private-cookie; fixture_refresh=synthetic-private-refresh';
    private Container $previous;
    private Container $container;
    private string $root;
    private DownloadContinuationRuns $runs;
    private SmsDownloadContinuation $downloads;
    private array $actor = ['email' => 'fixture@example.invalid', 'username' => 'fixture-user', 'permissionIds' => ['cmd_sms_download']];

    protected function setUp(): void
    {
        require_once dirname(__DIR__,3).'/cmd-runner/app/Http/Middleware/VerifyCmdSession.php';
        require_once dirname(__DIR__,3).'/cmd-runner/app/Http/Middleware/EnforceReportPolicy.php';
        $this->previous = Container::getInstance();
        $this->container = new class extends Container {
            public function environment(...$values): string|bool { return $values === [] ? 'testing' : in_array('testing', $values, true); }
            public function abort($code, $message = '', array $headers = []): never { throw new HttpException($code, $message, null, $headers); }
        };
        Container::setInstance($this->container);
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sms-download-grant-test-'.bin2hex(random_bytes(8));
        mkdir($this->root,0700);
        $this->container->instance('config', new Repository(['app' => ['url' => 'https://runner.fixture.invalid'],
            'sms-exports' => ['download_continuation_enabled' => true],
            'services' => ['cmd' => ['auth_enabled' => true, 'auth_validate_url' => 'https://identity.fixture.invalid', 'auth_cache_ttl' => 60]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array'], 'file' => ['driver' => 'file', 'path' => $this->root.'/cache'], 'null' => ['driver' => 'null']]]]));
        $this->container->instance('cache', new CacheManager($this->container));
        $this->container->instance('files', new \Illuminate\Filesystem\Filesystem);
        $this->container->instance('encrypter', new Encrypter(str_repeat('x',32),'AES-256-CBC'));
        $this->container->instance(Factory::class,new Factory);
        $this->container->bind('db', static function(): never { throw new \RuntimeException('No business database is permitted in the download continuation.'); });
        $routes = new RouteCollection;
        foreach (['GET' => 'cmd.sms_run.download', 'POST' => 'cmd.sms_run.download_continue'] as $method => $name) {
            $routes->add((new Route([$method], 'api/cmd/mail-drop-export-report/runs/{requestId}/download', fn () => null))->name($name));
        }
        $urls = new UrlGenerator($routes,Request::create('https://runner.fixture.invalid'));
        $urls->setKeyResolver(static fn () => 'synthetic-download-signing-key');$this->container->instance('url',$urls);
        Facade::clearResolvedInstances();Facade::setFacadeApplication($this->container);
        Request::macro('hasValidSignatureWhileIgnoring', function($ignore = [],$absolute = true) { return URL::hasValidSignature($this,$absolute,$ignore); });
        Carbon::setTestNow('2026-10-25T18:00:00Z');Http::preventStrayRequests();
        file_put_contents($this->root.'/ordinary.csv',"first_name,amount,phone,date\nSynthetic,13000,0005550100,2026-10-21\n");
        $this->runs = new DownloadContinuationRuns($this->root.'/ordinary.csv');
        $this->downloads = new SmsDownloadContinuation($this->runs);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();Facade::clearResolvedInstances();Container::setInstance($this->previous);Facade::setFacadeApplication($this->previous);
        self::assertMatchesRegularExpression('/sms-download-grant-test-[a-f0-9]{16}$/D',$this->root);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname());else unlink($file->getPathname()); }
        rmdir($this->root);
    }

    private function issuer(): Request
    {
        $request = Request::create('https://identity.fixture.invalid/api/cmd/mail-drop-export-report/runs/'.self::ID.'/link');
        $request->headers->set('Cookie',self::COOKIE);$request->attributes->set('cmd_session_fresh',true);$request->attributes->set('cmd_user',$this->actor);
        return $request;
    }

    private function key(array $descriptor): string { return 'sms:download-continuation:'.hash('sha256',$descriptor['fields']['grant']); }

    private function post(array $descriptor, array $fields = [], string $method = 'POST'): Request
    {
        $request = Request::create($descriptor['url'],$method,$fields ?: $descriptor['fields']);
        $route = new Route([$method],'api/cmd/mail-drop-export-report/runs/{requestId}/download',fn () => null);$route->bind($request);
        $request->setRouteResolver(static fn () => $route);return $request;
    }

    private function identity(?array $actor = null, int $status = 200): void
    {
        Http::swap(new Factory);Http::preventStrayRequests();
        Http::fake(['identity.fixture.invalid/*' => Http::response($actor ?? $this->actor,$status)]);
    }

    private function pipeline(Request $request): Response
    {
        return (new ValidateSignature)->handle($request,fn ($r) => (new SmsDownloadSessionBridge($this->downloads))->handle($r,
            fn ($r) => (new SmsFreshSession)->handle($r,fn ($r) => (new \App\Http\Middleware\VerifyCmdSession)->handle($r,
                fn ($r) => (new SmsPermission)->handle($r,fn ($r) => (new SmsRunDownloadContinuationController($this->downloads,new SmsRunController($this->runs)))($r,self::ID),'download')))),'relative');
    }

    private function refuses(callable $work, int $status): void
    {
        try { $work();self::fail('Expected secure download refusal.'); }
        catch (HttpExceptionInterface $error) { self::assertSame($status,$error->getStatusCode(),$error->getMessage()); }
    }

    public function test_issue_exposes_only_body_grant_and_keeps_credentials_encrypted_server_side(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);
        self::assertSame(['method','url','fields'],array_keys($descriptor));self::assertSame('POST',$descriptor['method']);
        self::assertSame(['grant'],array_keys($descriptor['fields']));self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/',$descriptor['fields']['grant']);
        parse_str(parse_url($descriptor['url'],PHP_URL_QUERY),$query);self::assertSame(now()->timestamp+60,(int)$query['expires']);
        foreach ([self::COOKIE,$this->actor['email'],$descriptor['fields']['grant']] as $secret) self::assertStringNotContainsString($secret,$descriptor['url']);
        $encrypted = Cache::get($this->key($descriptor));self::assertIsString($encrypted);
        foreach ([self::COOKIE,$this->actor['email'],'permissionIds'] as $secret) self::assertStringNotContainsString($secret,$encrypted);
        $payload = json_decode($this->container->make('encrypter')->decrypt($encrypted,false),true,24,JSON_THROW_ON_ERROR);
        self::assertSame(self::COOKIE,$payload['cookie']);self::assertSame(hash('sha256',self::COOKIE),$payload['session_sha256']);
        self::assertArrayNotHasKey('permissionIds',$payload);self::assertSame(60,$payload['expires_at']-$payload['issued_at']);
        self::assertSame(1,$this->runs->fileCalls);self::assertSame(1,$this->runs->urlCalls);Http::assertNothingSent();
    }

    public function test_issuance_gate_keeps_legacy_link_and_allows_an_existing_grant_to_finish(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(), self::ID);
        config(['sms-exports.download_continuation_enabled' => false]);
        $fileCalls = $this->runs->fileCalls;
        $this->refuses(fn () => $this->downloads->issue($this->issuer(), self::ID), 503);
        $link = (new SmsRunController($this->runs))->link($this->issuer(), self::ID)->getData(true);
        self::assertArrayHasKey('download_url', $link);
        self::assertArrayNotHasKey('download_request', $link);
        self::assertSame($fileCalls, $this->runs->fileCalls);
        $this->identity();
        self::assertSame(200, $this->pipeline($this->post($descriptor))->getStatusCode());
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)), 410);
    }

    public function test_issuance_requires_attested_fresh_actor_permission_and_nonempty_cookie(): void
    {
        $request = $this->issuer();$request->attributes->remove('cmd_session_fresh');$this->refuses(fn () => $this->downloads->issue($request,self::ID),401);
        $request = $this->issuer();$request->attributes->set('cmd_user',['email'=>'fixture@example.invalid','permissionIds'=>['cmd_report_mail_drop_export']]);$this->refuses(fn () => $this->downloads->issue($request,self::ID),403);
        $request = $this->issuer();$request->attributes->remove('cmd_user');$this->refuses(fn () => $this->downloads->issue($request,self::ID),401);
        foreach (['',str_repeat('x',16385),"fixture=bad\r\nvalue"] as $cookie) {
            $request = $this->issuer();$request->headers->set('Cookie',$cookie);$this->refuses(fn () => $this->downloads->issue($request,self::ID),401);
        }
        self::assertSame(0,$this->runs->fileCalls);self::assertSame(0,$this->runs->urlCalls);Http::assertNothingSent();
    }

    public function test_fresh_redemption_streams_existing_csv_and_restores_unrelated_browser_cookie(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);$request = $this->post($descriptor);$request->headers->set('Cookie','runner_unrelated=old');
        $this->identity();$response = $this->pipeline($request);
        self::assertInstanceOf(BinaryFileResponse::class,$response);self::assertSame($this->runs->path,$response->getFile()->getPathname());
        self::assertSame('text/csv; charset=UTF-8',$response->headers->get('Content-Type'));
        self::assertStringContainsString('private',$response->headers->get('Cache-Control'));self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        Http::assertSent(fn ($r) => $r->header('Cookie') === [self::COOKIE]);Http::assertSentCount(1);
        self::assertSame('runner_unrelated=old',$request->headers->get('Cookie'));self::assertFalse($request->attributes->has(SmsDownloadContinuation::ATTRIBUTE));
        self::assertNull(Cache::get($this->key($descriptor)));self::assertTrue(Cache::get($this->key($descriptor).':used'));
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)),410);Http::assertSentCount(1);
    }

    public function test_revoked_permission_and_changed_actor_are_rechecked_before_any_file_read(): void
    {
        foreach ([['email'=>'fixture@example.invalid','username'=>'fixture-user','permissionIds'=>['cmd_report_mail_drop_export']],
            ['email'=>'other@example.invalid','username'=>'other-user','permissionIds'=>['cmd_sms_download']]] as $actor) {
            $descriptor = $this->downloads->issue($this->issuer(),self::ID);$calls = $this->runs->fileCalls;
            Cache::put('cmd_session:'.hash('sha256',self::COOKIE),['user'=>$this->actor],60);
            $request = $this->post($descriptor);$this->identity($actor);$this->refuses(fn () => $this->pipeline($request),403);
            self::assertSame($calls,$this->runs->fileCalls);self::assertNull($request->headers->get('Cookie'));
            self::assertFalse($request->attributes->has(SmsDownloadContinuation::ATTRIBUTE));Http::assertSentCount(1);
            $this->refuses(fn () => $this->pipeline($this->post($descriptor)),410);Http::assertSentCount(1);
        }
    }

    public function test_expired_identity_signature_and_wrong_method_or_missing_body_never_stream(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);$this->identity([],401);
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)),401);self::assertSame(1,$this->runs->fileCalls);
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);Carbon::setTestNow(now()->addSeconds(61));
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)),403);
        Carbon::setTestNow('2026-10-25T18:00:00Z');$descriptor = $this->downloads->issue($this->issuer(),self::ID);
        $this->refuses(fn () => $this->downloads->consume($this->post($descriptor,[],'GET'),self::ID),405);
        $request = $this->post($descriptor);$request->request->replace([]);$this->refuses(fn () => $this->pipeline($request),400);
        $request = $this->post($descriptor);$request->request->set('cmd_user',$this->actor);$this->refuses(fn () => $this->pipeline($request),400);
        self::assertIsString(Cache::get($this->key($descriptor)));
    }

    public function test_valid_signature_for_another_run_cannot_redeem_the_original_grant(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);
        $descriptor['url'] = 'https://runner.fixture.invalid'.URL::temporarySignedRoute('cmd.sms_run.download_continue',now()->addSeconds(60),['requestId'=>self::OTHER],false);
        $this->refuses(fn () => $this->downloads->consume($this->post($descriptor),self::OTHER),410);Http::assertNothingSent();
        self::assertSame(1,$this->runs->fileCalls);
    }

    public function test_artifact_replacement_is_denied_after_fresh_authorization(): void
    {
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);$this->identity();
        file_put_contents($this->runs->path,"changed frozen bytes\n");
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)),409);Http::assertSentCount(1);
        $this->refuses(fn () => $this->pipeline($this->post($descriptor)),410);
    }

    public function test_ordinary_and_external_original_zip_bytes_remain_immutable_through_existing_handler(): void
    {
        foreach (['prospect-record-v1','prospect-external-sent-v1'] as $policy) {
            $external=$policy==='prospect-external-sent-v1';$path=$this->root.'/'.($external?'original-nine-column.zip':'ordinary-four-column.zip');
            $zip=new ZipArchive;self::assertTrue($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL));
            $csv=$external?implode(',',\Cmd\Reports\Services\SmsExternalArtifact::HEADER)."\nSynthetic,Fixture,13000,0005550100,,,,,2026-10-21\n"
                :"first_name,debt_amount,phone_number,sms_send_date\nSynthetic,13000,0005550100,2026-10-21\n";
            foreach ([1,2,3] as $part) $zip->addFromString('SMS_Data_Part'.$part.'.csv',$csv);
            $zip->close();$sha=hash_file('sha256',$path);$this->runs->path=$path;
            $this->runs->policy = $policy;$descriptor = $this->downloads->issue($this->issuer(),self::ID);$this->identity();
            $response = $this->pipeline($this->post($descriptor));self::assertSame('application/zip',$response->headers->get('Content-Type'));
            self::assertSame($path,$response->getFile()->getPathname());self::assertSame($sha,hash_file('sha256',$path));self::assertSame($policy,$this->runs->policy);
        }
        self::assertSame(4,$this->runs->fileCalls);self::assertSame(2,$this->runs->urlCalls);
    }

    public function test_cache_lock_contention_and_atomic_used_fence_prevent_duplicate_redemption(): void
    {
        config(['cache.default'=>'file']);self::assertTrue(Cache::add('fixture-cache-probe','synthetic',60));$descriptor = $this->downloads->issue($this->issuer(),self::ID);
        $lock = Cache::store()->getStore()->lock($this->key($descriptor).':consume',10);self::assertTrue($lock->get());
        try { $this->refuses(fn () => $this->downloads->consume($this->post($descriptor),self::ID),409);self::assertIsString(Cache::get($this->key($descriptor))); }
        finally { $lock->release(); }
        Cache::add($this->key($descriptor).':used',true,70);
        $this->refuses(fn () => $this->downloads->consume($this->post($descriptor),self::ID),410);Http::assertNothingSent();
        $descriptor = $this->downloads->issue($this->issuer(),self::ID);$receipt = $this->downloads->consume($this->post($descriptor),self::ID);
        self::assertSame(self::COOKIE,$receipt['cookie']);$this->refuses(fn () => $this->downloads->consume($this->post($descriptor),self::ID),410);
    }

    public function test_two_independent_processes_can_consume_one_shared_file_cache_grant_only_once(): void
    {
        config(['cache.default'=>'file']);$descriptor = $this->downloads->issue($this->issuer(),self::ID);
        $context = ['root'=>$this->root,'descriptor'=>$descriptor,'artifact'=>$this->runs->path,'at'=>now()->toIso8601String()];
        file_put_contents($this->root.'/worker-context.json',json_encode($context,JSON_THROW_ON_ERROR));
        $bootstrap = var_export(dirname(__DIR__).'/bootstrap-workspace.php',true);
        $definitions = var_export(__FILE__,true);
        $worker = '<?php require '.$bootstrap.';require '.$definitions.';'.<<<'PHP'
$s=json_decode(file_get_contents($argv[1]),true,24,JSON_THROW_ON_ERROR);
$app=new class extends \Illuminate\Container\Container {
 public function environment(...$values):string|bool{return $values===[]?'testing':in_array('testing',$values,true);}
};
\Illuminate\Container\Container::setInstance($app);
$app->instance('config',new \Illuminate\Config\Repository(['services'=>['cmd'=>['auth_enabled'=>true]],
 'cache'=>['default'=>'file','stores'=>['file'=>['driver'=>'file','path'=>$s['root'].'/cache']]]]));
$app->instance('files',new \Illuminate\Filesystem\Filesystem);
$app->instance('cache',new \Illuminate\Cache\CacheManager($app));
$app->instance('encrypter',new \Illuminate\Encryption\Encrypter(str_repeat('x',32),'AES-256-CBC'));
$routes=new \Illuminate\Routing\RouteCollection;
$urls=new \Illuminate\Routing\UrlGenerator($routes,\Illuminate\Http\Request::create('https://runner.fixture.invalid'));
$urls->setKeyResolver(static fn()=>'synthetic-download-signing-key');$app->instance('url',$urls);
\Illuminate\Support\Facades\Facade::clearResolvedInstances();\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Illuminate\Support\Carbon::setTestNow($s['at']);
$service=new \Cmd\Reports\Services\SmsDownloadContinuation(new \Cmd\Reports\Tests\Unit\DownloadContinuationRuns($s['artifact']));
$request=\Illuminate\Http\Request::create($s['descriptor']['url'],'POST',$s['descriptor']['fields']);
file_put_contents($s['root'].'/ready-'.$argv[2],'ready');$deadline=microtime(true)+10;
while(!is_file($s['root'].'/go')){if(microtime(true)>$deadline)throw new \RuntimeException('Synthetic race barrier timed out.');usleep(1000);}
try{$service->consume($request,'11111111-2222-4333-8444-555555555555');echo json_encode(['status'=>200],JSON_THROW_ON_ERROR);}
catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){echo json_encode(['status'=>$error->getStatusCode()],JSON_THROW_ON_ERROR);}
PHP;
        file_put_contents($this->root.'/worker.php',$worker);$processes=[];
        try {
            foreach ([1,2] as $number) {
                $pipes=[];$process=proc_open([PHP_BINARY,$this->root.'/worker.php',$this->root.'/worker-context.json',(string)$number],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process);fclose($pipes[0]);$processes[] = [$process,$pipes];
            }
            $deadline=microtime(true)+10;
            while (!is_file($this->root.'/ready-1') || !is_file($this->root.'/ready-2')) {
                if (microtime(true)>$deadline) self::fail('Both isolated consumers must reach the synthetic race barrier.');
                usleep(10000);
            }
            file_put_contents($this->root.'/go','go');$statuses=[];
            foreach ($processes as [$process,$pipes]) {
                $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$stderr);self::assertSame('',$stderr);
                $statuses[]=json_decode($stdout,true,16,JSON_THROW_ON_ERROR)['status'];
            }
            $processes=[];sort($statuses);self::assertSame(200,$statuses[0]);self::assertContains($statuses[1],[409,410]);
            self::assertNull(Cache::get($this->key($descriptor)));self::assertTrue(Cache::get($this->key($descriptor).':used'));
        } finally {
            file_put_contents($this->root.'/go','go');
            foreach ($processes as [$process,$pipes]) {
                foreach ([1,2] as $i) if (is_resource($pipes[$i])) fclose($pipes[$i]);
                if (is_resource($process)) proc_close($process);
            }
        }
    }

    public function test_corrupt_or_rebound_encrypted_receipts_fail_closed_and_never_reach_identity(): void
    {
        foreach (['purpose'=>'another-purpose','method'=>'GET','version'=>2,'request_id'=>self::OTHER,'relative_sha256'=>str_repeat('a',64),
            'issued_at'=>now()->timestamp+1,'expires_at'=>now()->timestamp-1,'session_sha256'=>str_repeat('b',64)] as $field=>$value) {
            $descriptor=$this->downloads->issue($this->issuer(),self::ID);$key=$this->key($descriptor);
            $payload=json_decode($this->container->make('encrypter')->decrypt(Cache::get($key),false),true,24,JSON_THROW_ON_ERROR);$payload[$field]=$value;
            Cache::put($key,$this->container->make('encrypter')->encrypt(json_encode($payload,JSON_THROW_ON_ERROR),false),60);
            $this->refuses(fn()=>$this->pipeline($this->post($descriptor)),410);
            self::assertNull(Cache::get($key));self::assertTrue(Cache::get($key.':used'));
        }
        Http::assertNothingSent();
    }

    public function test_signed_get_still_requires_its_own_actual_session_and_does_not_consume_post_grant(): void
    {
        $descriptor=$this->downloads->issue($this->issuer(),self::ID);
        Http::fake(['identity.fixture.invalid/*'=>fn($r)=>$r->header('Cookie')===[self::COOKIE]?Http::response($this->actor):Http::response([],401)]);
        $get = static fn($request)=>(new ValidateSignature)->handle($request,fn($r)=>(new SmsFreshSession)->handle($r,
            fn($r)=>(new \App\Http\Middleware\VerifyCmdSession)->handle($r,
                fn($r)=>(new SmsPermission)->handle($r,fn()=>new Response('existing-get-handler',204),'download'))),'relative');
        $this->refuses(fn()=>$get(Request::create($descriptor['url'])),401);
        $request=Request::create($descriptor['url']);$request->headers->set('Cookie',self::COOKIE);
        self::assertSame(204,$get($request)->getStatusCode());self::assertIsString(Cache::get($this->key($descriptor)));
    }

    public function test_missing_crypto_null_cache_and_auth_disabled_fail_closed(): void
    {
        $this->container->forgetInstance('encrypter');$this->refuses(fn () => $this->downloads->issue($this->issuer(),self::ID),503);
        $this->container->instance('encrypter',new Encrypter(str_repeat('x',32),'AES-256-CBC'));
        config(['cache.default'=>'null']);$this->refuses(fn () => $this->downloads->issue($this->issuer(),self::ID),503);
        config(['cache.default'=>'array','services.cmd.auth_enabled'=>false]);$this->refuses(fn () => $this->downloads->issue($this->issuer(),self::ID),503);Http::assertNothingSent();
    }

    public function test_uncertain_cache_delete_is_fenced_and_never_authenticates_or_streams(): void
    {
        $cache=new class(new \Illuminate\Cache\ArrayStore) extends \Illuminate\Cache\Repository {
            public function forget($key) { return false; }
        };
        $this->container->make('cache')->extend('refuse-delete',fn()=>$cache);
        config(['cache.default'=>'refuse-delete','cache.stores.refuse-delete'=>['driver'=>'refuse-delete']]);
        $descriptor=$this->downloads->issue($this->issuer(),self::ID);
        $this->refuses(fn()=>$this->downloads->consume($this->post($descriptor),self::ID),503);
        self::assertIsString(Cache::get($this->key($descriptor)));self::assertTrue(Cache::get($this->key($descriptor).':used'));
        $this->refuses(fn()=>$this->downloads->consume($this->post($descriptor),self::ID),410);
        self::assertSame(1,$this->runs->fileCalls);Http::assertNothingSent();
    }

    public function test_unavailable_real_cache_lock_refuses_issuance_without_a_delegation(): void
    {
        $store=new class extends \Illuminate\Cache\ArrayStore {
            public function lock($name,$seconds=0,$owner=null) { throw new \RuntimeException('Synthetic unavailable lock backend.'); }
        };
        $cache=new \Illuminate\Cache\Repository($store);
        $this->container->make('cache')->extend('unavailable-lock',fn()=>$cache);
        config(['cache.default'=>'unavailable-lock','cache.stores.unavailable-lock'=>['driver'=>'unavailable-lock']]);
        $this->refuses(fn()=>$this->downloads->issue($this->issuer(),self::ID),503);
        self::assertSame([],$store->all());Http::assertNothingSent();
    }

    public function test_actual_route_keeps_signed_get_and_requires_the_full_post_guard_chain(): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($this->container),$this->container);
        $this->container->instance('router',$router);Facade::clearResolvedInstance('router');require dirname(__DIR__,2).'/routes/sms-download.php';
        $get = $router->getRoutes()->match(Request::create('/api/cmd/mail-drop-export-report/runs/'.self::ID.'/download','GET'));
        self::assertSame(['api','signed:relative',SmsFreshSession::class,\App\Http\Middleware\VerifyCmdSession::class,SmsPermission::class.':download'],$get->gatherMiddleware());
        $post = $router->getRoutes()->match(Request::create('/api/cmd/mail-drop-export-report/runs/'.self::ID.'/download','POST'));
        self::assertSame(['api','signed:relative',SmsDownloadSessionBridge::class,SmsFreshSession::class,\App\Http\Middleware\VerifyCmdSession::class,SmsPermission::class.':download'],$post->gatherMiddleware());
        self::assertSame('cmd.sms_run.download_continue',$post->getName());
    }
}

/** Only the already-gated URL/file methods are permitted; the continuation never writes business state. */
final class DownloadContinuationRuns extends SmsRunService
{
    public int $urlCalls = 0;
    public int $fileCalls = 0;
    public string $policy = 'prospect-record-v1';
    public function __construct(public string $path) {}
    public function downloadUrl(string $requestId): string
    {
        $this->urlCalls++;
        return 'https://runner.fixture.invalid'.URL::temporarySignedRoute('cmd.sms_run.download',now()->addMinutes(15),['requestId'=>$requestId],false);
    }
    public function filePath(string $requestId): string { $this->fileCalls++;return $this->path; }
}
