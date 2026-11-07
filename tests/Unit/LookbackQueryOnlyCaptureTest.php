<?php
declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\GenerateRetentionBonusCommission;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class LookbackQueryOnlyCaptureTest extends TestCase
{
    public function test_actual_command_queries_only_and_preserves_canonical_snapshot(): void
    {
        $this->runCapture(false);
    }

    public function test_nonzero_source_awards_and_exact_sales_deductions_are_archived_without_azure_writes(): void
    {
        $this->runCapture(true);
    }

    private function runCapture(bool $nonzero): void
    {
        $root = sys_get_temp_dir() . '/lookback-query-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/app/commission-snapshots/2098-01/retention-lookback', 0700, true);
        $canonical = $root . '/app/commission-snapshots/2098-01/retention-lookback/prior.xlsx';
        file_put_contents($canonical, 'original');
        foreach (['LDR', 'Progress Law'] as $company) file_put_contents($root . '/app/Retention Lookback Commission - ' . $company . ' - All.xlsx', 'canonical-original');
        $previous = Container::getInstance(); $previousLog = Log::getFacadeRoot();
        $app = new class($root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . ($path === '' ? '' : '/' . $path); }
            public function runningUnitTests(): bool { return true; }
        };
        Container::setInstance($app); Log::swap(new NullLogger);
        $old = []; foreach (['PAYROLL_LOOKBACK_QUERY_ONLY','PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT','PAYROLL_COMMISSION_RUN_ID'] as $key) $old[$key] = getenv($key);
        putenv('PAYROLL_LOOKBACK_QUERY_ONLY=1'); putenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=1'); putenv('PAYROLL_COMMISSION_RUN_ID=' . str_repeat('a', 32));
        $sql = new LookbackQueryConnector($nonzero);
        $command = new class($sql, $nonzero) extends GenerateRetentionBonusCommission {
            public function __construct(private DBConnector $sql, private bool $nonzero) { parent::__construct(); }
            protected function initSnowflake(string $source): DBConnector { return $this->sql; }
            protected function initSqlServer(string $source): DBConnector { return $this->sql; }
            protected function fetchBase(DBConnector $sf, array $cfg, string $end): array { return $this->nonzero ? [['ID' => $cfg['custom_agent'] === 742096 ? '101' : '201', 'CLIENT' => 'Synthetic Client', 'RETENTION_AGENT' => 'Named Retention', 'ENROLLED_DEBT' => 10000]] : []; }
            protected function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList, string $end): array { return ['101' => '2098-01-01', '201' => '2098-01-01']; }
            protected function fetchRetainedDates(DBConnector $sf, string $idList): array { return ['101' => ['2098-01-02'], '201' => ['2098-01-02']]; }
        };
        $command->setLaravel($app);
        try {
            $output = new BufferedOutput;
            $code = $command->run(new ArrayInput(['source'=>'both','period'=>'2098-01-01','--no-email'=>true], $command->getDefinition()), $output);
            self::assertSame(0, $code, $output->fetch());
            self::assertNotEmpty($sql->calls);
            foreach ($sql->calls as $call) self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $call['sql']);
            self::assertSame('original', file_get_contents($canonical));
            foreach (['LDR', 'Progress Law'] as $company) self::assertSame('canonical-original', file_get_contents($root . '/app/Retention Lookback Commission - ' . $company . ' - All.xlsx'));
            self::assertDirectoryDoesNotExist($root . '/app/payroll-lookback-query-tmp/' . str_repeat('a', 32) . '/ldr');
            self::assertDirectoryDoesNotExist($root . '/app/payroll-lookback-query-tmp/' . str_repeat('a', 32) . '/plaw');
            $archive = new PayrollReportArtifactArchive;
            foreach (['ldr','plaw'] as $source) {
                $artifact = $archive->read('lookback_' . $source, '2098-01-01', str_repeat('a', 32));
                self::assertTrue($artifact['context']['queryOnly']);
                self::assertSame('retention', $artifact['context']['commissionResults']['reportKind']);
                self::assertSame($source, $artifact['context']['salesDeductions']['source']);
                self::assertSame(str_repeat('a', 32), $artifact['context']['salesDeductions']['runId']);
                $rows = $artifact['context']['salesDeductions']['rows'];
                self::assertCount($nonzero ? 1 : 0, $rows);
                if ($nonzero) {
                    self::assertSame(50, $rows[0]['Agent_Deduction']);
                    self::assertSame(701, $rows[0]['Employee_PK']);
                    self::assertSame('LLG-' . ($source === 'ldr' ? '101' : '201'), $rows[0]['CID']);
                    self::assertSame(50, $artifact['context']['commissionResults']['rows'][0]['amount']);
                }
            }
        } finally {
            foreach ($old as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
            Container::setInstance($previous); $previousLog === null ? Log::clearResolvedInstance('log') : Log::swap($previousLog);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else { @chmod($file->getPathname(), 0600); unlink($file->getPathname()); } } rmdir($root);
        }
    }
}

final class LookbackQueryConnector extends DBConnector
{
    public array $calls = [];
    public function __construct(private bool $nonzero) {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new \LogicException('Query-only mode attempted a write.');
        $data = [];
        if ($this->nonzero && str_starts_with($sql, 'SELECT LLG_ID, First_Payment')) $data = [['LLG_ID' => 'LLG-101', 'first_payment_cleared_date' => '2097-10-05', 'agent' => 'Alice Sales', 'commission_rate' => 1], ['LLG_ID' => 'LLG-201', 'first_payment_cleared_date' => '2097-10-05', 'agent' => 'Alice Sales', 'commission_rate' => 1]];
        elseif ($this->nonzero && str_starts_with($sql, 'SELECT LLG_ID, Agent')) $data = array_map(static fn ($id) => ['LLG_ID' => $id, 'Agent' => 'Alice Sales'], $params);
        elseif ($this->nonzero && str_starts_with($sql, 'SELECT PK, Employee_Name')) $data = [['PK' => 701, 'Employee_Name' => 'Alice Sales']];
        elseif ($this->nonzero && str_starts_with($sql, 'SELECT Agent FROM')) $data = [['Agent' => 'Named Retention']];
        elseif ($this->nonzero && str_starts_with($sql, 'SELECT Employee_Name')) $data = [['Employee_Name' => 'Named Retention', 'Location' => 'Jordan', 'Company' => 'Liberty Debt Relief']];
        return ['success' => true, 'data' => $data];
    }
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        if (!str_contains($sql, 'FROM TRANSACTIONS')) throw new \LogicException('Unexpected source query.');
        $data = [];
        foreach (['101', '201'] as $id) foreach (['2097-10-05', '2097-11-05', '2097-12-05'] as $date) $data[] = ['CONTACT_ID' => $id, 'CLEARED_DATE' => $date];
        return ['success' => true, 'data' => $data];
    }
}
