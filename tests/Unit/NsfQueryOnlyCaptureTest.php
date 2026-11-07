<?php
declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class NsfQueryOnlyCaptureTest extends TestCase
{
    public function test_actual_command_queries_only_and_preserves_canonical_snapshot(): void
    {
        $root = sys_get_temp_dir() . '/nsf-query-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/app/commission-snapshots/2098-01/nsf', 0700, true);
        $canonical = $root . '/app/commission-snapshots/2098-01/nsf/prior.xlsx';
        file_put_contents($canonical, 'original');
        $previous = Container::getInstance(); $previousLog = Log::getFacadeRoot();
        $app = new class($root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . ($path === '' ? '' : '/' . $path); }
            public function runningUnitTests(): bool { return true; }
        };
        Container::setInstance($app); Log::swap(new NullLogger);
        $old = []; foreach (['PAYROLL_NSF_QUERY_ONLY','PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT','PAYROLL_COMMISSION_RUN_ID'] as $key) $old[$key] = getenv($key);
        putenv('PAYROLL_NSF_QUERY_ONLY=1'); putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=1'); putenv('PAYROLL_COMMISSION_RUN_ID=' . str_repeat('a', 32));
        $sql = new RecordingSqlServerConnector;
        $command = new class($sql) extends GenerateNSFCommissionReport {
            public function __construct(private DBConnector $sql) { parent::__construct(); }
            protected function initSnowflake(string $source): DBConnector { return $this->sql; }
            protected function initSqlServer(string $source): DBConnector { return $this->sql; }
            protected function fetchNSFRows(DBConnector $sf, array $cfg, string $startDate, string $endDate): array { return []; }
        };
        $command->setLaravel($app);
        try {
            $output = new BufferedOutput;
            $code = $command->run(new ArrayInput(['source'=>'both','period'=>'2098-01-01','--no-email'=>true], $command->getDefinition()), $output);
            self::assertSame(0, $code, $output->fetch());
            self::assertNotEmpty($sql->calls);
            foreach ($sql->calls as $call) self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $call['sql']);
            self::assertSame('original', file_get_contents($canonical));
            self::assertDirectoryDoesNotExist($root . '/app/payroll-nsf-query-tmp/' . str_repeat('a', 32) . '/ldr');
            self::assertDirectoryDoesNotExist($root . '/app/payroll-nsf-query-tmp/' . str_repeat('a', 32) . '/plaw');
            $archive = new PayrollReportArtifactArchive;
            foreach (['ldr','plaw'] as $source) {
                $artifact = $archive->read('nsf_' . $source, '2098-01-01', str_repeat('a', 32));
                self::assertTrue($artifact['context']['queryOnly']);
                self::assertSame('nsf', $artifact['context']['commissionResults']['reportKind']);
            }
        } finally {
            foreach ($old as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
            Container::setInstance($previous); $previousLog === null ? Log::clearResolvedInstance('log') : Log::swap($previousLog);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else { @chmod($file->getPathname(), 0600); unlink($file->getPathname()); } } rmdir($root);
        }
    }
}
