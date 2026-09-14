<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\GenerateEnrollmentSummaryReport;
use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\PeelOffsBuilder;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The month-bucket rows of the Enrollment Summary sheet after the 2026-09-10 PRD and Jacob's 14 Sep
 * widening: every month block gains the Unprocessed rows (each month its own payers) and the
 * ledger exclusion; everything else in the blocks is the old behaviour.
 */
class EnrollmentSummaryReportPeelOffRowsTest extends TestCase
{
    private const SNAPSHOT = '2026-09-10';
    private const WINDOW = '2026-08-31';   // August tranche not sold => window anchored on August
    private const CRITERIA = "AND State NOT IN ('WI') ";

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('log', new class {
            public function __call(string $method, array $arguments): void
            {
            }
        });
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_every_month_gets_unprocessed_rows_and_the_exclusion(): void
    {
        [$rows, $connector] = $this->buildTotalColumn();
        $labels = array_column($rows, 'label');

        // PRD §1.1 — placement: count row after the NSF count, debt row after NSF Peel Offs.
        $this->assertSame(
            ["NSFs of Client's Paying in August", "Unprocessed Payments of Client's Paying in August", "Net New Clients Paying in August"],
            array_slice($labels, array_search("NSFs of Client's Paying in August", $labels, true), 3)
        );
        $this->assertSame(
            ['NSF Peel Offs Paying in August', 'Unprocessed Peel Offs Paying in August', 'Total Net Debt Enrolled Paying in August'],
            array_slice($labels, array_search('NSF Peel Offs Paying in August', $labels, true), 3)
        );

        // Values: the exclusion changes the first month's NSF / Cancel figures (3, 2, 300, 200) and
        // the Unprocessed rows come from the builder (2 clients, $150).
        $this->assertSame(3, $this->value($rows, "Cancels of Client's Paying in August"));
        $this->assertSame(2, $this->value($rows, "NSFs of Client's Paying in August"));
        $this->assertSame(2, $this->value($rows, "Unprocessed Payments of Client's Paying in August"));
        $this->assertSame(10 - 3 - 2 - 2, $this->value($rows, 'Net New Clients Paying in August'));
        $this->assertSame(300.0, $this->value($rows, 'Cancel Peel Offs Paying in August'));
        $this->assertSame(200.0, $this->value($rows, 'NSF Peel Offs Paying in August'));
        $this->assertSame(150.0, $this->value($rows, 'Unprocessed Peel Offs Paying in August'));
        $this->assertSame(1000.0 - 300.0 - 200.0 - 150.0, $this->value($rows, 'Total Net Debt Enrolled Paying in August'));

        // Jacob 2026-09-14 12:08: the Unprocessed rows are on every month block, each holding only
        // its own month's payers — September has one client ($70), October (a future month) is 0.
        // The NSF / Cancel exclusion applies to every month too: a client whose first draft never
        // processed moves into a later bucket when the new draft bounces (First_Payment_Date is
        // recomputed) and must not be peeled a second time there.
        $this->assertSame(1, $this->value($rows, "Unprocessed Payments of Client's Paying in September"));
        $this->assertSame(70.0, $this->value($rows, 'Unprocessed Peel Offs Paying in September'));
        $this->assertSame(10 - 3 - 2 - 1, $this->value($rows, 'Net New Clients Paying in September'));
        $this->assertSame(1000.0 - 300.0 - 200.0 - 70.0, $this->value($rows, 'Total Net Debt Enrolled Paying in September'));

        $this->assertSame(0, $this->value($rows, "Unprocessed Payments of Client's Paying in October"));
        $this->assertSame(0.0, $this->value($rows, 'Unprocessed Peel Offs Paying in October'));
        $this->assertSame(10 - 3 - 2, $this->value($rows, 'Net New Clients Paying in October'));
        $this->assertSame(1000.0 - 300.0 - 200.0, $this->value($rows, 'Total Net Debt Enrolled Paying in October'));

        foreach (['September', 'October'] as $month) {
            $this->assertSame(
                ["NSFs of Client's Paying in {$month}", "Unprocessed Payments of Client's Paying in {$month}", "Net New Clients Paying in {$month}"],
                array_slice($labels, array_search("NSFs of Client's Paying in {$month}", $labels, true), 3),
                "{$month}: same placement as the first month"
            );
            $this->assertSame(3, $this->value($rows, "Cancels of Client's Paying in {$month}"));
            $this->assertSame(2, $this->value($rows, "NSFs of Client's Paying in {$month}"));
        }

        // The exclusion clause reached the four NSF / Cancel statements of every month (3 months)
        // and nothing else (Gross / Total Deals / Sellable are untouched).
        $scalarCalls = array_filter($connector->calls, static fn (array $c): bool => str_starts_with(ltrim($c['sql']), 'SELECT COUNT') || str_starts_with(ltrim($c['sql']), 'SELECT SUM'));
        $excluded = array_filter($scalarCalls, static fn (array $c): bool => str_contains($c['sql'], 'NOT EXISTS'));
        $this->assertCount(12, $excluded);
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], array_values(array_unique(array_map(static fn (array $c) => $c['params'][1], $excluded))));
        foreach ($excluded as $call) {
            $this->assertMatchesRegularExpression('/WHERE (Cancel_Date|NSF_Date) = \?/', $call['sql']);
        }

        // The existing 3-day projection rule still derives its cutoff from the same grace period.
        $this->assertNotEmpty($connector->callsMatching("/>= '2026-09-07'\)/"));
    }

    public function test_ldr_column_takes_only_the_ldr_share_of_unprocessed(): void
    {
        [$rows] = $this->buildTotalColumn('LDR');

        $this->assertSame(1, $this->value($rows, "Unprocessed Payments of Client's Paying in August", 'LDR'));
        $this->assertSame(100.0, $this->value($rows, 'Unprocessed Peel Offs Paying in August', 'LDR'));
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: RecordingSqlServerConnector}
     */
    private function buildTotalColumn(string $columnKey = 'Total'): array
    {
        $scalar = static function (string $sql, array $params): array {
            $excluded = str_contains($sql, 'NOT EXISTS');
            $value = match (true) {
                str_contains($sql, 'SELECT COUNT') && str_contains($sql, 'Cancel_Date = ?') => $excluded ? 3 : 5,
                str_contains($sql, 'SELECT COUNT') && str_contains($sql, 'NSF_Date = ?') => $excluded ? 2 : 4,
                str_contains($sql, 'SELECT SUM') && str_contains($sql, 'Cancel_Date = ?') => $excluded ? 300 : 500,
                str_contains($sql, 'SELECT SUM') && str_contains($sql, 'NSF_Date = ?') => $excluded ? 200 : 400,
                str_contains($sql, 'SELECT COUNT') && str_contains($sql, 'Welcome_Call_Date = ?') => 10,
                str_contains($sql, 'SELECT SUM') && str_contains($sql, 'Welcome_Call_Date = ?') => 1000,
                default => 0,
            };

            return ['success' => true, 'data' => [['v' => $value]], 'row_count' => 1];
        };

        $peelOffRow = static fn (string $llg, float $debt, string $plan, string $paid = '2026-08-05'): array => [
            'LLG_ID' => $llg, 'Client' => 'C', 'Agent' => 'A', 'Debt_Amount' => (string) $debt,
            'First_Payment_Date' => null, 'Cancel_Date' => null, 'NSF_Date' => null,
            'Enrollment_Plan' => $plan, 'Payment_Date' => $paid,
        ];

        $connector = new RecordingSqlServerConnector([
            '/CREATE TABLE/' => ['success' => true, 'data' => [], 'row_count' => 0],
            '/First_Payment_Cleared_Date IS NULL/' => ['success' => true, 'data' => [
                $peelOffRow('LLG-1', 100.0, 'LDR 29%'),
                $peelOffRow('LLG-2', 50.0, 'Progress Law 29%'),
                $peelOffRow('LLG-3', 70.0, 'LDR 29%', '2026-09-03'),   // a September payer
            ]],
            '/SELECT LLG_ID, Client, Agent/' => ['success' => true, 'data' => [], 'row_count' => 0],
            '/^\s*SELECT (COUNT|SUM)/' => $scalar,
        ]);

        $command = new GenerateEnrollmentSummaryReport();
        $builder = new PeelOffsBuilder($connector, self::SNAPSHOT, '2026-08-01', '2026-10-31', self::CRITERIA, static fn (): array => []);
        $builder->collect();

        $property = new ReflectionProperty($command, 'peelOffs');
        $property->setValue($command, $builder);

        $method = new ReflectionMethod($command, 'buildMonthBuckets');
        $method->invoke($command, $connector, $columnKey, self::CRITERIA, self::WINDOW, self::SNAPSHOT);

        $rows = (new ReflectionProperty($command, 'rows'))->getValue($command);

        return [$rows, $connector];
    }

    private function value(array $rows, string $label, string $columnKey = 'Total'): mixed
    {
        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return $row['values'][$columnKey] ?? null;
            }
        }
        $this->fail("Row '{$label}' not found");
    }
}
