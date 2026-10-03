<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// The package has no standalone vendor tree. Run with the host's Composer autoloader.
require_once getenv('DEBT_TEST_COMMAND') ?: dirname(__DIR__, 2) . '/src/Console/Commands/SyncContactsData.php';

class SyncContactsDebtTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new \Illuminate\Container\Container();
        $container->instance('log', new \Psr\Log\NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function setSource(SyncContactsData $command, string $source): void
    {
        (new ReflectionProperty(SyncContactsData::class, 'source'))->setValue($command, $source);
    }

    public static function mappingCases(): iterable
    {
        foreach (['LDR', 'PLAW'] as $source) {
            yield "$source missing loan" => [$source, null, 13920, 13000.0, 13920.0, 'enrolled_fallback'];
            yield "$source zero loan" => [$source, 0, 23951.25, 23000.0, 23951.25, 'enrolled_fallback'];
            yield "$source oversized loan" => [$source, 999999999.99, 8815, 8000.0, 8815.0, 'enrolled_fallback'];
            yield "$source invalid loan" => [$source, 'invalid', 8815, 8000.0, 8815.0, 'enrolled_fallback'];
            yield "$source negative loan" => [$source, -20, 8815, 8000.0, 8815.0, 'enrolled_fallback'];
            yield "$source independent enrolled debt" => [$source, 30000, 50000.25, 30000.0, 50000.25, 'loan'];
            yield "$source existing rounding" => [$source, 33, 15000, 0.0, 15000.0, 'loan'];
            yield "$source no enrolled debt" => [$source, null, null, 0.0, 0.0, 'no_debt'];
            yield "$source upper valid bound" => [$source, 999999, 15000, 999000.0, 15000.0, 'loan'];
        }
        yield 'LT stays independent' => ['LT', 30000, 50000, 30000.0, 30000.0, 'loan'];
        yield 'LT keeps cap' => ['LT', 1000000, 50000, 0.0, 0.0, 'no_debt'];
    }

    #[DataProvider('mappingCases')]
    public function test_debt_mapping(string $source, mixed $loan, mixed $enrolled, float $amount, float $exact, string $basis): void
    {
        $command = new SyncContactsData();
        $this->setSource($command, $source);
        $method = new ReflectionMethod(SyncContactsData::class, 'processChunk');
        $seen = [];
        $args = [[['LLG_ID' => '123', 'DEBT_AMOUNT_CUSTOM' => $loan, 'ENROLLED_DEBT' => $enrolled]], [],
            ['categories' => [], 'affiliate_agents' => []]];
        if ($method->getNumberOfParameters() === 4) {
            $args[] = &$seen;
        }
        [$rows] = $method->invokeArgs($command, $args);
        self::assertSame($amount, $rows[0]['debt_amount']);
        self::assertSame($exact, $rows[0]['debt_enrolled']);
        self::assertSame($basis, $rows[0]['debt_basis']);
    }

    public function test_contact_inserts_keep_external_id_without_duplicate_tp_id(): void
    {
        foreach (['LDR', 'PLAW', 'LT'] as $source) {
            $command = new SyncContactsData();
            $this->setSource($command, $source);
            (new ReflectionProperty(SyncContactsData::class, 'targetTable'))->setValue(
                $command, $source === 'LT' ? 'TblContacts' : 'TblContacts' . $source
            );
            [$rows] = (new ReflectionMethod(SyncContactsData::class, 'processChunk'))->invoke(
                $command,
                [['LLG_ID' => '123', 'EXTERNAL_ID' => ' 12345678901 ', 'ENROLLED_DEBT' => 13920]],
                [],
                ['categories' => [], 'affiliate_agents' => []]
            );
            self::assertSame('12345678901', $rows[0]['external_id']);
            self::assertArrayNotHasKey('tp_id', $rows[0]);
            $fields = (new ReflectionMethod(SyncContactsData::class, 'contactFields'))->invoke($command);
            $pdo = $this->getMockBuilder(\PDO::class)->disableOriginalConstructor()->onlyMethods(['exec'])->getMock();
            $pdo->expects(self::once())->method('exec')->willReturnCallback(function (string $sql): int {
                self::assertStringNotContainsString('TP_ID', $sql);
                self::assertStringContainsString('External_ID', $sql);
                self::assertSame(1, substr_count($sql, "'12345678901'"));
                return 1;
            });
            (new ReflectionMethod(SyncContactsData::class, 'insertContactRows'))->invoke($command, $pdo, $fields, $rows);
        }
    }

    public function test_missing_enrolled_alias_cannot_silently_become_zero(): void
    {
        $command = new SyncContactsData();
        $this->setSource($command, 'PLAW');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing ENROLLED_DEBT');
        (new ReflectionMethod(SyncContactsData::class, 'resolveDebtValues'))->invoke($command, ['DEBT_AMOUNT_CUSTOM' => null]);
    }

    public function test_standard_query_restores_only_active_enrolled_debts(): void
    {
        $command = new SyncContactsData();
        (new ReflectionProperty(SyncContactsData::class, 'debtAmountCustomId'))->setValue($command, 743019);
        (new ReflectionProperty(SyncContactsData::class, 'agentCustomId'))->setValue($command, 742153);
        $sql = (new ReflectionMethod(SyncContactsData::class, 'buildStandardQuery'))->invoke($command, '2021-07-01', 0, 10);
        self::assertStringContainsString('SUM(d.ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT', $sql);
        self::assertStringContainsString('WHERE d.ENROLLED = 1 AND d._FIVETRAN_DELETED = FALSE', $sql);
        self::assertStringContainsString('GROUP BY d.CONTACT_ID', $sql);
        self::assertStringContainsString('d.ENROLLED_DEBT,', $sql);
        $lt = (new ReflectionMethod(SyncContactsData::class, 'buildLTQuery'))->invoke($command, '2021-07-01', 0, 10);
        self::assertStringNotContainsString('FROM DEBTS', $lt);
    }

    private function dryRun(string $failure = '', bool $debtOnly = false): array
    {
        // No cache facade root: any attempted cache/database lock fails this test.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $snowflake = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $calls = 0;
        $snowflake->method('query')->willReturnCallback(function (string $sql) use (&$calls): array {
            self::assertMatchesRegularExpression('/^(WITH|SELECT)\b/i', ltrim($sql));
            return ['data' => $calls++ ? [] : [
                ['LLG_ID' => '123', 'EXTERNAL_ID' => '12345678900', 'ENROLLED_DATE' => '2026-09-01', 'ENROLLED_DEBT' => '13920.00', 'DEBT_AMOUNT_CUSTOM' => null],
                ['LLG_ID' => '124', 'EXTERNAL_ID' => '12345678901', 'ENROLLED_DEBT' => '50000.25', 'DEBT_AMOUNT_CUSTOM' => '30000'],
                ['LLG_ID' => '125', 'ENROLLED_DEBT' => null, 'DEBT_AMOUNT_CUSTOM' => null],
                ['LLG_ID' => '126', 'ENROLLED_DEBT' => null, 'DEBT_AMOUNT_CUSTOM' => null],
            ]];
        });
        $sqlServer = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['querySqlServer'])->getMock();
        $reads = [];
        $sqlServer->method('querySqlServer')->willReturnCallback(function (string $sql) use (&$reads, $failure): array {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $sql, 'Dry-run attempted a write or temp-table statement');
            $reads[] = $sql;
            if (str_contains($sql, 'FROM TblContacts')) {
                if ($failure === 'query') {
                    return ['success' => false, 'error' => 'simulated read failure'];
                }
                $data = [
                    ['LLG_ID' => 'LLG-123', 'Debt_Amount' => '0', 'Debt_Enrolled' => '0'],
                    ['LLG_ID' => 'LLG-124', 'Debt_Amount' => '30000', 'Debt_Enrolled' => '30000'],
                    ['LLG_ID' => 'LLG-125', 'Debt_Amount' => '0', 'Debt_Enrolled' => '0'],
                ];
                if ($failure === 'duplicate') {
                    $data[] = $data[0];
                }
                return ['success' => true, 'data' => $data];
            }
            return ['success' => true, 'data' => []];
        });
        $command = $this->getMockBuilder(SyncContactsData::class)
            ->onlyMethods(['initializeSnowflakeConnector', 'initializeSqlServerConnector'])->getMock();
        $command->method('initializeSnowflakeConnector')->willReturn($snowflake);
        $command->method('initializeSqlServerConnector')->willReturn($sqlServer);
        $input = new ArrayInput(['--source' => 'LDR', '--dry-run' => true, '--full' => true,
            '--no-match' => !$debtOnly, '--debt-only' => $debtOnly], $command->getDefinition());
        $output = new BufferedOutput();
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        $status = $command->handle();
        return [$status, $output->fetch(), $reads];
    }

    public function test_dry_run_is_select_only_and_reports_actual_changes(): void
    {
        [$status, $text, $reads] = $this->dryRun();
        self::assertSame(0, $status, $text);
        self::assertCount(4, $reads, 'Enrollment, exact mailer, fallback mailer and debt comparison SELECTs expected');
        self::assertStringContainsString('Debt_Amount 0.00 => 13000.00; Debt_Enrolled 0.00 => 13920.00', $text);
        self::assertStringContainsString('Debt_Amount 30000.00 => 30000.00; Debt_Enrolled 30000.00 => 50000.25', $text);
        self::assertStringContainsString('4 processed, 2 existing changed, 1 unchanged, 1 new', $text);
        self::assertStringContainsString('Writes performed: 0', $text);
        self::assertStringContainsString('would be upserted', $text);
        self::assertStringNotContainsString('sync completed successfully', $text);
    }

    public function test_failed_comparison_read_cannot_report_success(): void
    {
        [$status, $text] = $this->dryRun('query');
        self::assertSame(1, $status);
        self::assertStringContainsString('simulated read failure', $text);
        self::assertStringNotContainsString('[SUCCESS]', $text);
    }

    public function test_duplicate_target_ids_fail_instead_of_silent_overwrite(): void
    {
        [$status, $text] = $this->dryRun('duplicate');
        self::assertSame(1, $status);
        self::assertStringContainsString('Duplicate target LLG_ID LLG-123', $text);
        self::assertStringNotContainsString('[SUCCESS]', $text);
    }

    public function test_debt_only_skips_unrelated_lookups_and_matching(): void
    {
        [$status, $text, $reads] = $this->dryRun('', true);
        self::assertSame(0, $status, $text);
        self::assertCount(1, $reads);
        self::assertStringContainsString('FROM TblContactsLDR', $reads[0]);
        self::assertStringContainsString('4 processed, 2 existing changed, 1 unchanged, 1 new', $text);
        self::assertStringContainsString('post-sync matching were not evaluated', $text);
    }

    public function test_debt_only_cannot_be_used_to_write_or_run_the_orchestrator(): void
    {
        foreach ([['--source' => 'LDR'], ['--dry-run' => true], ['--dry-run' => true, '--source' => 'LT']] as $options) {
            $command = new SyncContactsData();
            $input = new ArrayInput($options + ['--debt-only' => true], $command->getDefinition());
            $output = new BufferedOutput();
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            self::assertSame(1, $command->handle());
            self::assertStringContainsString('--debt-only requires', $output->fetch());
        }
    }
}
