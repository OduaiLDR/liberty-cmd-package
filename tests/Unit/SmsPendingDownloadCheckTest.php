<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsPendingDownloadCheck;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SmsPendingDownloadCheckTest extends TestCase
{
    private const ID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    private Container $previous;

    private SmsPendingDownloadCheck $check;

    /** @var array<int, string> */
    private array $started = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['sms-exports' => ['pending_check_background_rows' => 500000],
            'cache' => ['default' => 'array', 'stores' => ['file' => ['driver' => 'array'], 'array' => ['driver' => 'array']], 'prefix' => 'sms-test']]));
        $app->instance('cache', new CacheManager($app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Carbon::setTestNow(Carbon::parse('2026-11-06T10:00:00Z'));
        $started = &$this->started;
        $this->check = new class($started) extends SmsPendingDownloadCheck
        {
            public bool $queueDown = false;

            public function __construct(private array &$started) {}

            protected function start(string $requestId): void
            {
                if ($this->queueDown) throw new RuntimeException('queue is down');
                $this->started[] = $requestId;
            }
        };
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    public function test_only_files_above_the_limit_use_the_worker(): void
    {
        self::assertFalse($this->check->runsInBackground((object) ['Selected' => 500000]));
        self::assertTrue($this->check->runsInBackground((object) ['Selected' => 500001]));
    }

    public function test_asking_again_while_it_runs_starts_only_one_check(): void
    {
        self::assertFalse($this->check->ready(self::ID));
        self::assertFalse($this->check->ready(self::ID));
        self::assertFalse($this->check->ready(strtolower(self::ID)));
        self::assertSame([strtolower(self::ID)], $this->started);
        self::assertFalse($this->check->passed(self::ID));
    }

    public function test_a_pass_opens_the_download_and_then_expires(): void
    {
        $this->check->ready(self::ID);
        $this->check->recordPass(self::ID);
        self::assertTrue($this->check->ready(self::ID));
        self::assertTrue($this->check->passed(self::ID));

        Carbon::setTestNow(now()->addSeconds(SmsPendingDownloadCheck::PASS_SECONDS + 1));
        self::assertFalse($this->check->passed(self::ID));
        self::assertFalse($this->check->ready(self::ID), 'An expired pass needs a new check.');
        self::assertCount(2, $this->started);
    }

    public function test_a_held_member_is_shown_once_and_the_next_click_checks_again(): void
    {
        $this->check->ready(self::ID);
        $this->check->recordHeld(self::ID, 'A frozen SMS member is contacted.');
        try {
            $this->check->ready(self::ID);
            self::fail('A held file must not be released.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
            self::assertSame('A frozen SMS member is contacted.', $error->getMessage());
        }
        self::assertFalse($this->check->passed(self::ID));
        self::assertFalse($this->check->ready(self::ID));
        self::assertCount(2, $this->started);
    }

    public function test_a_failed_check_never_passes(): void
    {
        $this->check->ready(self::ID);
        $this->check->recordFailure(self::ID);
        self::assertFalse($this->check->passed(self::ID));
        $this->expectException(HttpException::class);
        $this->check->ready(self::ID);
    }

    public function test_a_queue_problem_leaves_nothing_stuck(): void
    {
        $this->check->queueDown = true;
        try {
            $this->check->ready(self::ID);
            self::fail('The queue error must reach the page.');
        } catch (RuntimeException $error) {
            self::assertSame('queue is down', $error->getMessage());
        }
        $this->check->queueDown = false;
        self::assertFalse($this->check->ready(self::ID));
        self::assertCount(1, $this->started);
    }

    public function test_a_broken_cache_counts_as_not_passed(): void
    {
        $this->check->recordPass(self::ID);
        self::assertTrue($this->check->passed(self::ID));
        Container::getInstance()->instance('cache', new class
        {
            public function store(?string $name = null): never
            {
                throw new RuntimeException('cache is down');
            }
        });
        Facade::clearResolvedInstances();
        self::assertFalse($this->check->passed(self::ID), 'The download must run the check itself.');
    }

    public function test_the_check_only_repeats_after_a_slow_hash(): void
    {
        self::assertFalse(SmsRunService::pendingCheckIsStale(100.0, 101.0));
        self::assertFalse(SmsRunService::pendingCheckIsStale(100.0, 129.9));
        self::assertTrue(SmsRunService::pendingCheckIsStale(100.0, 130.0));
    }
}
