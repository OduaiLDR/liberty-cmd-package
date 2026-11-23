<?php
declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\CompareLookbackShadow;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\GenerateRetentionBonusCommission;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class LookbackQueryOnlyCaptureTest extends TestCase
{
    private const PERIOD = '2098-01-01';
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_actual_command_queries_only_and_preserves_canonical_snapshot(): void
    {
        $this->runCapture(false);
    }

    public function test_nonzero_source_awards_and_exact_sales_deductions_are_archived_without_azure_writes(): void
    {
        $this->runCapture(true);
    }

    public function test_structured_replay_matches_employee_calculations_without_the_workbooks(): void
    {
        $this->runCapture(true, true);
    }

    private function runCapture(bool $nonzero, bool $shadow = false): void
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
        $sql = new LookbackQueryConnector($nonzero, $shadow);
        $command = new class($sql, $nonzero, $shadow) extends GenerateRetentionBonusCommission {
            public function __construct(private DBConnector $sql, private bool $nonzero, private bool $shadow) { parent::__construct(); }
            protected function initSnowflake(string $source): DBConnector { return $this->sql; }
            protected function initSqlServer(string $source): DBConnector { return $this->sql; }
            protected function fetchBase(DBConnector $sf, array $cfg, string $end): array
            {
                if (!$this->nonzero) return [];
                $ldr = $cfg['custom_agent'] === 742096;
                $rows = [['ID' => $ldr ? '101' : '201', 'CLIENT' => 'Synthetic Client',
                    'RETENTION_AGENT' => 'Named Retention', 'ENROLLED_DEBT' => 10000]];
                if ($this->shadow && $ldr) {
                    $rows[] = ['ID' => '102', 'RETENTION_AGENT' => 'Named Retention', 'ENROLLED_DEBT' => 11111.11, 'RETENTION_DATE' => '2098-01-02'];
                    $rows[] = ['ID' => '103', 'RETENTION_AGENT' => 'Zero Retention', 'ENROLLED_DEBT' => 0];
                    $rows[] = ['ID' => '104', 'RETENTION_AGENT' => 'Andrea MendozE', 'ENROLLED_DEBT' => 1000];
                }
                return $rows;
            }
            protected function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList, string $end): array { return array_fill_keys(explode(',', $idList), '2098-01-01'); }
            protected function fetchRetainedDates(DBConnector $sf, string $idList): array { return array_fill_keys(explode(',', $idList), ['2098-01-02']); }
        };
        $command->setLaravel($app);
        try {
            $output = new BufferedOutput;
            $input = ['source' => 'both', 'period' => self::PERIOD, '--no-email' => true];
            if ($shadow) {
                $input['--structured-shadow'] = true;
                foreach ([['source' => 'ldr'], ['period' => ''], ['--test-recipient' => 'test@example.test']] as $invalid) {
                    self::assertSame(1, $command->run(new ArrayInput($invalid + $input, $command->getDefinition()), $output));
                }
                putenv('PAYROLL_LOOKBACK_QUERY_ONLY=0');
                self::assertSame(1, $command->run(new ArrayInput($input, $command->getDefinition()), $output));
                self::assertSame([], $sql->calls);
                putenv('PAYROLL_LOOKBACK_QUERY_ONLY=1');
            }
            $code = $command->run(new ArrayInput($input, $command->getDefinition()), $output);
            self::assertSame(0, $code, $output->fetch());
            self::assertNotEmpty($sql->calls);
            foreach ($sql->calls as $call) self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $call['sql']);
            self::assertSame('original', file_get_contents($canonical));
            foreach (['LDR', 'Progress Law'] as $company) self::assertSame('canonical-original', file_get_contents($root . '/app/Retention Lookback Commission - ' . $company . ' - All.xlsx'));
            self::assertSame([], glob($root . '/app/payroll-lookback-query-tmp/*/*', GLOB_ONLYDIR));
            $archive = new PayrollReportArtifactArchive;
            $artifacts = [];
            foreach (['ldr','plaw'] as $source) {
                $key = 'lookback_' . $source;
                $artifact = $artifacts[$key] = $archive->read($key, self::PERIOD, self::RUN_ID);
                self::assertTrue($artifact['context']['queryOnly']);
                self::assertSame('retention', $artifact['context']['commissionResults']['reportKind']);
                self::assertSame($source, $artifact['context']['salesDeductions']['source']);
                self::assertSame(str_repeat('a', 32), $artifact['context']['salesDeductions']['runId']);
                $rows = $artifact['context']['salesDeductions']['rows'];
                self::assertCount($shadow && $source === 'ldr' ? 4 : (int) $nonzero, $rows);
                if ($nonzero) {
                    self::assertSame(50, $rows[0]['Agent_Deduction']);
                    self::assertSame(701, $rows[0]['Employee_PK']);
                    self::assertSame('LLG-' . ($source === 'ldr' ? '101' : '201'), $rows[0]['CID']);
                    $totals = array_column($artifact['context']['commissionResults']['rows'], 'amount', 'agentKey');
                    self::assertSame($shadow && $source === 'ldr' ? 71.11 : 50, $totals['named retention']);
                }
                if (!$shadow) {
                    self::assertArrayNotHasKey('lookbackPricing', $artifact['context']);
                }
            }
            if ($shadow) {
                $this->assertShadowReplay($app, $artifacts);
            }
        } finally {
            foreach ($old as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
            Container::setInstance($previous); $previousLog === null ? Log::clearResolvedInstance('log') : Log::swap($previousLog);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else { @chmod($file->getPathname(), 0600); unlink($file->getPathname()); } } rmdir($root);
        }
    }
    private function assertShadowReplay(Container $app, array $artifacts): void
    {
        $read = static fn (array $evidence, string $agent, string $scope = 'both'): array =>
            CustomerServiceCalculationSnapshot::forStructuredLookbackAgent($evidence, self::PERIOD, self::RUN_ID, $agent, $scope);
        $legacy = [];
        foreach (['named retention', 'zero retention', 'andrea mendoze', 'absent employee'] as $agent) {
            $legacy[$agent] = CustomerServiceCalculationSnapshot::forArchivedAgent($artifacts, self::PERIOD, 'retention_lookback', $agent, 'both');
            self::assertSame($legacy[$agent], $read($artifacts, $agent));
        }
        self::assertSame([71.11, 50.0], array_column($legacy['named retention']['components'], 'amount'));
        self::assertSame([
            ['clientId' => '101', 'amount' => 50.0, 'paymentDate' => '2097-10-05'],
            ['clientId' => '102', 'amount' => 21.11, 'retentionDate' => '2098-01-02', 'paymentDate' => '2097-10-05'],
        ], $legacy['named retention']['components'][0]['lines']);
        self::assertSame([], $legacy['zero retention']['components'][0]['lines']);
        self::assertSame(0.0, $legacy['zero retention']['components'][0]['amount']);
        self::assertSame('andrea mendoza', $legacy['andrea mendoze']['components'][0]['sourceAgentKey']);
        self::assertSame([], $legacy['absent employee']['components']);
        self::assertNull($artifacts['lookback_ldr']['context']['salesDeductions']['rows'][1]['Agent_Deduction']);

        $command = new CompareLookbackShadow;
        $command->setLaravel($app);
        $output = new class extends ConsoleOutput {
            public string $captured = '';
            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured .= $message . ($newline ? PHP_EOL : '');
            }
        };
        $errors = new BufferedOutput;
        $output->setErrorOutput($errors);
        $argument = new ArgvInput(['artisan', self::PERIOD, self::RUN_ID, 'named retention'], $command->getDefinition());
        self::assertSame(0, $command->run($argument, $output));
        self::assertSame('', $errors->fetch());
        $comparison = json_decode($output->captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($comparison['matches']);
        self::assertSame(['matches', 'workbookMs', 'structuredMs'], array_keys($comparison));

        foreach ($artifacts as &$artifact) {
            $path = $artifact['path'];
            @chmod($path, 0600);
            self::assertTrue(unlink($path));
            $artifact = json_decode(file_get_contents(dirname($path) . '/artifact.json'), true, flags: JSON_THROW_ON_ERROR);
        }
        unset($artifact);
        self::assertSame($legacy['named retention'], $read($artifacts, 'named retention'));
        $changed = $artifacts;
        $changed['lookback_plaw']['context']['lookbackPricing']['rows'][0]['amount'] = 51;
        $mixed = $artifacts;
        $mixed['lookback_plaw']['context']['lookbackPricing']['captureId'] = str_repeat('b', 32);
        $missingPayment = $artifacts;
        unset($missingPayment['lookback_plaw']['context']['lookbackPricing']['rows'][0]['paymentDate']);
        $invalidEvidence = [$changed, $this->rehashPlaw($mixed), $this->rehashPlaw($changed),
            $this->rehashPlaw($missingPayment), ['lookback_ldr' => $artifacts['lookback_ldr']]];
        foreach ($invalidEvidence as $invalid) {
            try {
                $read($invalid, 'named retention', 'ldr');
            } catch (\RuntimeException | \InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
                continue;
            }
            self::fail('The reader accepted changed, incomplete or mixed evidence.');
        }
    }

    private function rehashPlaw(array $artifacts): array
    {
        $artifacts['lookback_plaw']['contextSha256'] = hash('sha256', json_encode(
            $artifacts['lookback_plaw']['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        return $artifacts;
    }
}

final class LookbackQueryConnector extends DBConnector
{
    public array $calls = [];
    public function __construct(private bool $nonzero, private bool $shadow) {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new \LogicException('Query-only mode attempted a write.');
        $data = [];
        if ($this->nonzero && str_starts_with($sql, 'SELECT LLG_ID, First_Payment')) {
            $rates = [101 => 1, 201 => 1] + ($this->shadow ? [102 => 0, 103 => 0, 104 => 0] : []);
            foreach ($rates as $id => $rate) {
                $data[] = ['LLG_ID' => 'LLG-' . $id, 'first_payment_cleared_date' => '2097-10-05',
                    'agent' => 'Alice Sales', 'commission_rate' => $rate];
            }
        }
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
        $ids = $this->shadow ? ['101', '102', '103', '104', '201'] : ['101', '201'];
        foreach ($ids as $id) foreach (['2097-10-05', '2097-11-05', '2097-12-05'] as $date) $data[] = ['CONTACT_ID' => $id, 'CLEARED_DATE' => $date];
        return ['success' => true, 'data' => $data];
    }
}
