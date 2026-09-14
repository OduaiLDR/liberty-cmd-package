<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\PeelOffsBuilder;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * PRD "Enrollment Summary Report — Peel Offs Update" (Jacob, 2026-09-10): the unprocessed rule,
 * the weekend window, the ledger exclusion, and the per-column aggregates.
 */
class EnrollmentSummaryPeelOffsBuilderTest extends TestCase
{
    private const WINDOW = ['2026-08-01', '2026-10-31'];   // Aug (tranche month), Sep, Oct
    private const CRITERIA = "AND State NOT IN ('WI') ";

    protected function setUp(): void
    {
        parent::setUp();

        // PeelOffsBuilder logs through the Log facade when the ledger is unavailable; give the
        // facade a container with a no-op logger so that path can run outside Laravel.
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

    public function test_grace_period_is_three_days_and_the_cutoff_is_derived_from_it(): void
    {
        $this->assertSame(3, PeelOffsBuilder::UNPROCESSED_GRACE_DAYS);
        $this->assertSame('2026-08-16', PeelOffsBuilder::unprocessedCutoff('2026-08-19'));
    }

    public function test_previous_report_date_skips_weekends(): void
    {
        $this->assertSame('2026-08-18', PeelOffsBuilder::previousReportDate('2026-08-19')); // Wed -> Tue
        $this->assertSame('2026-08-14', PeelOffsBuilder::previousReportDate('2026-08-17')); // Mon -> Fri
        $this->assertSame('2026-08-14', PeelOffsBuilder::previousReportDate('2026-08-15')); // Sat -> Fri
        $this->assertSame('2026-09-04', PeelOffsBuilder::previousReportDate('2026-09-07')); // Mon -> Fri
    }

    /**
     * PRD §2 example: payment scheduled 8/15, not processed, no NSF, no cancel => enters the
     * unprocessed category on 8/19. The window the builder queries must contain 8/15 on 8/19 and
     * on no other day.
     */
    public function test_jacobs_example_a_payment_on_8_15_is_newly_unprocessed_on_8_19_only(): void
    {
        $seen = [];
        foreach (['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21'] as $reportDate) {
            [$lower, $upper] = $this->unprocessedWindow($reportDate);
            $seen[$reportDate] = ('2026-08-15' >= $lower && '2026-08-15' < $upper);
        }

        $this->assertSame([
            '2026-08-17' => false,
            '2026-08-18' => false,
            '2026-08-19' => true,
            '2026-08-20' => false,
            '2026-08-21' => false,
        ], $seen);
    }

    /**
     * PRD §1.3: the report does not run on weekends, so Monday's window has to pick up everything
     * whose grace period ran out on Saturday, Sunday or Monday — and Friday's own run still owns
     * Friday.
     */
    public function test_monday_window_covers_saturday_sunday_and_monday_entries(): void
    {
        // Monday 2026-08-17: previous report Friday 08-14.
        [$lower, $upper] = $this->unprocessedWindow('2026-08-17');
        $this->assertSame('2026-08-11', $lower); // cutoff(Fri 08-14) — payments from here on were still in grace on Friday
        $this->assertSame('2026-08-14', $upper); // cutoff(Mon 08-17) — exclusive

        // Payment dates 08-11, 08-12, 08-13 enter unprocessed on 08-15 (Sat), 08-16 (Sun), 08-17 (Mon).
        foreach (['2026-08-11', '2026-08-12', '2026-08-13'] as $paymentDate) {
            $this->assertTrue($paymentDate >= $lower && $paymentDate < $upper, "{$paymentDate} should be in Monday's window");
        }
        // 08-10 entered on Friday 08-14 and belongs to Friday's report; 08-14 is still in grace.
        $this->assertFalse('2026-08-10' >= $lower && '2026-08-10' < $upper);
        $this->assertFalse('2026-08-14' >= $lower && '2026-08-14' < $upper);

        // Friday's own window ends where Monday's begins, so nothing is counted twice or missed.
        [, $fridayUpper] = $this->unprocessedWindow('2026-08-14');
        $this->assertSame($fridayUpper, $lower);
    }

    public function test_unprocessed_query_requires_no_clear_no_cancel_no_nsf_and_the_window(): void
    {
        $connector = $this->connector();
        $this->builder($connector, '2026-08-19')->collect();

        $calls = $connector->callsMatching('/First_Payment_Cleared_Date IS NULL/');
        $this->assertCount(1, $calls);
        $sql = $calls[0]['sql'];
        $this->assertStringContainsString('Cancel_Date IS NULL AND NSF_Date IS NULL', $sql);
        $this->assertStringContainsString(self::CRITERIA, $sql);
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertSame(['2026-08-01', '2026-10-31', '2026-08-16', '2026-08-15'], $calls[0]['params']);
    }

    public function test_nsf_and_cancel_queries_exclude_clients_ledgered_before_the_report_date(): void
    {
        $connector = $this->connector();
        $this->builder($connector, '2026-08-19')->collect();

        foreach (['/WHERE NSF_Date = \?/', '/WHERE Cancel_Date = \?/'] as $pattern) {
            $calls = $connector->callsMatching($pattern);
            $this->assertCount(1, $calls, $pattern);
            $this->assertStringContainsString("l.Peel_Off_Type = 'Unprocessed'", $calls[0]['sql']);
            $this->assertStringContainsString("l.Report_Date < '2026-08-19'", $calls[0]['sql']);
            $this->assertSame(['2026-08-19', '2026-08-01', '2026-10-31'], $calls[0]['params']);
        }
    }

    /**
     * A ledgered client whose first payment has since CLEARED re-entered the sellable population
     * (the 3-day rule counts cleared payments), so a later NSF or cancel is a new peel off and
     * must not be excluded. The exclusion therefore only bites while First_Payment_Cleared_Date
     * is still NULL — which also keeps Jacob's example (new payment bounces, never clears) excluded.
     */
    public function test_exclusion_lapses_once_the_first_payment_clears(): void
    {
        $sql = $this->builder($this->connector(), '2026-08-19')->ledgerExclusionSql();

        $this->assertMatchesRegularExpression(
            '/AND \(First_Payment_Cleared_Date IS NOT NULL OR NOT EXISTS \(/',
            $sql
        );
        $this->assertStringContainsString('l.LLG_ID = TblEnrollment.LLG_ID', $sql);
    }

    public function test_ledger_table_is_created_once_and_named_for_the_exclusion(): void
    {
        $connector = $this->connector();
        $builder = $this->builder($connector, '2026-08-19');
        $builder->collect();
        $builder->ledgerExclusionSql();

        $creates = $connector->callsMatching('/CREATE TABLE dbo\.TblEnrollmentPeelOffs/');
        $this->assertCount(1, $creates, 'ensureLedgerTable must be memoised');
        $this->assertStringContainsString("IF OBJECT_ID('dbo.TblEnrollmentPeelOffs', 'U') IS NULL", $creates[0]['sql']);
        $this->assertStringNotContainsString('IDENTITY', $creates[0]['sql'], 'the local clone cannot recreate IDENTITY columns');
        $this->assertSame([], $builder->warnings());
    }

    public function test_unavailable_ledger_disables_the_exclusion_and_warns_instead_of_failing(): void
    {
        $connector = $this->connector([
            '/CREATE TABLE/' => ['success' => false, 'error' => 'CREATE TABLE permission denied', 'data' => []],
        ]);
        $builder = $this->builder($connector, '2026-08-19');
        $rows = $builder->collect();

        $this->assertSame('', $builder->ledgerExclusionSql());
        $this->assertCount(1, $builder->warnings());
        $this->assertStringContainsString('permission denied', $builder->warnings()[0]);
        foreach ($connector->callsMatching('/^\s*SELECT/') as $call) {
            $this->assertStringNotContainsString('NOT EXISTS', $call['sql']);
        }
        $this->assertSame(['nsf', 'cancel', 'unprocessed'], array_keys($rows));
        $this->assertSame(['attempted' => 4, 'written' => 0, 'failed' => 0], $builder->writeLedger(), 'nothing is written without a ledger');
    }

    public function test_rows_are_normalised_grouped_progress_law_first_and_sorted_by_debt_desc(): void
    {
        $rows = $this->builder($this->connector(), '2026-08-19')->collect();

        $this->assertSame(
            ['LLG-3', 'LLG-1', 'LLG-2', 'LLG-4'],
            array_column($rows['unprocessed'], 'LLG_ID'),
            'Progress Law first, then LDR, largest debt first within each (months interleave; Paying In tells them apart)'
        );
        $progress = $rows['unprocessed'][0];
        $this->assertSame('Progress Law', $progress['Company']);
        $this->assertSame('2026-08-15', $progress['Payment_Date']);
        $this->assertSame('2026-08-19', $progress['Unprocessed_Date'], 'payment date + grace + 1');
        $this->assertNull($progress['First_Payment_Date']);
        $this->assertSame(2500.5, $progress['Debt_Amount']);

        $this->assertSame('LDR', $rows['unprocessed'][1]['Company']);
        $this->assertSame('2026-08-14', $rows['unprocessed'][1]['First_Payment_Date'], 'SQL datetime strings become Y-m-d');
    }

    public function test_company_is_decided_by_progress_in_the_enrollment_plan(): void
    {
        $this->assertSame('Progress Law', PeelOffsBuilder::companyFor('Progress Law 29% with ProLaw'));
        $this->assertSame('Progress Law', PeelOffsBuilder::companyFor('LT progress law'));
        $this->assertSame('LDR', PeelOffsBuilder::companyFor('LDR 29% - with PLAW legal $17.95'));
        $this->assertSame('LDR', PeelOffsBuilder::companyFor(null));
    }

    public function test_aggregate_splits_by_column_the_same_way_as_the_report_columns(): void
    {
        $builder = $this->builder($this->connector(), '2026-08-19');

        // Whole window.
        $this->assertSame(['count' => 4, 'debt' => 7200.5], $builder->aggregate('unprocessed', 'Total'));
        $this->assertSame(['count' => 3, 'debt' => 4700.0], $builder->aggregate('unprocessed', 'LDR'));
        $this->assertSame(['count' => 1, 'debt' => 2500.5], $builder->aggregate('unprocessed', 'Legal'));
        $this->assertSame(['count' => 1, 'debt' => 800.0], $builder->aggregate('nsf', 'Total'));
        $this->assertSame(['count' => 0, 'debt' => 0.0], $builder->aggregate('cancel', 'Legal'));
    }

    /**
     * Jacob 2026-09-14 12:08: the Unprocessed rows go on every month block. Each block takes only
     * the clients paying in its month; a month with nobody (the third, always) reads 0.
     */
    public function test_aggregate_can_be_narrowed_to_one_paying_month(): void
    {
        $builder = $this->builder($this->connector(), '2026-08-19');

        $this->assertSame(['count' => 3, 'debt' => 6500.5], $builder->aggregate('unprocessed', 'Total', '2026-08-01'));
        $this->assertSame(['count' => 1, 'debt' => 700.0], $builder->aggregate('unprocessed', 'Total', '2026-09-01'));
        $this->assertSame(['count' => 1, 'debt' => 700.0], $builder->aggregate('unprocessed', 'LDR', '2026-09-15'), 'any day of the month selects it');
        $this->assertSame(['count' => 0, 'debt' => 0.0], $builder->aggregate('unprocessed', 'Total', '2026-10-01'));

        $september = array_values(array_filter($builder->collect()['unprocessed'], static fn (array $r): bool => $r['LLG_ID'] === 'LLG-4'))[0];
        $this->assertSame('2026-09', $september['Paying_In']);
        $this->assertSame('September', $september['Paying_In_Label']);
        $this->assertSame('2026-09-07', $september['Unprocessed_Date']);
    }

    public function test_write_ledger_inserts_each_newly_unprocessed_row_once(): void
    {
        $inserted = [];
        $connector = $this->connector([
            '/INSERT INTO dbo\.TblEnrollmentPeelOffs/' => static function (string $sql, array $params) use (&$inserted): array {
                $llg = $params[0];
                $isNew = !isset($inserted[$llg]);
                $inserted[$llg] = true;

                return ['success' => true, 'data' => [], 'row_count' => $isNew ? 1 : 0];
            },
        ]);
        $builder = $this->builder($connector, '2026-08-19');

        $this->assertSame(['attempted' => 4, 'written' => 4, 'failed' => 0], $builder->writeLedger());
        $this->assertSame(['attempted' => 4, 'written' => 0, 'failed' => 0], $builder->writeLedger(), 'a same-day re-run records nothing new');

        $first = $connector->callsMatching('/INSERT INTO dbo\.TblEnrollmentPeelOffs/')[0];
        $this->assertStringContainsString('WHERE NOT EXISTS', $first['sql']);
        // LLG_ID, type, report date, payment date, unprocessed date, debt, window month, then the NOT EXISTS pair.
        $this->assertSame(['LLG-3', 'Unprocessed', '2026-08-19', '2026-08-15', '2026-08-19', 2500.5, '2026-08-01', 'LLG-3', 'Unprocessed'], $first['params']);
    }

    public function test_write_ledger_reports_failures_rather_than_throwing(): void
    {
        $connector = $this->connector([
            '/INSERT INTO dbo\.TblEnrollmentPeelOffs/' => ['success' => false, 'error' => 'deadlock', 'data' => []],
        ]);

        $this->assertSame(['attempted' => 4, 'written' => 0, 'failed' => 4], $this->builder($connector, '2026-08-19')->writeLedger());
    }

    public function test_dates_must_be_iso_because_they_are_inlined_into_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PeelOffsBuilder($this->connector(), "2026-08-19' OR 1=1 --", self::WINDOW[0], self::WINDOW[1], self::CRITERIA);
    }

    /**
     * @return array{0: string, 1: string} [inclusive lower payment date, exclusive upper payment date]
     */
    private function unprocessedWindow(string $reportDate): array
    {
        $connector = $this->connector();
        $this->builder($connector, $reportDate)->collect();
        $params = $connector->callsMatching('/First_Payment_Cleared_Date IS NULL/')[0]['params'];

        return [$params[3], $params[2]];
    }

    private function builder(RecordingSqlServerConnector $connector, string $reportDate): PeelOffsBuilder
    {
        return new PeelOffsBuilder($connector, $reportDate, self::WINDOW[0], self::WINDOW[1], self::CRITERIA);
    }

    /**
     * @param array<string, array<string, mixed>|callable> $overrides
     */
    private function connector(array $overrides = []): RecordingSqlServerConnector
    {
        $row = static fn (string $llg, string $client, float $debt, string $plan, array $extra = []): array => array_merge([
            'LLG_ID' => $llg,
            'Client' => $client,
            'Agent' => 'Agent ' . $llg,
            'Debt_Amount' => (string) $debt,
            'First_Payment_Date' => null,
            'Cancel_Date' => null,
            'NSF_Date' => null,
            'Enrollment_Plan' => $plan,
            'Payment_Date' => '2026-08-15',
        ], $extra);

        return new RecordingSqlServerConnector($overrides + [
            '/CREATE TABLE/' => ['success' => true, 'data' => [], 'row_count' => 0],
            '/First_Payment_Cleared_Date IS NULL/' => ['success' => true, 'data' => [
                $row('LLG-1', 'Ada', 3000.0, 'LDR 29% - with PLAW legal $17.95', ['First_Payment_Date' => '2026-08-14 00:00:00.000']),
                $row('LLG-2', 'Bob', 1000.0, 'LDR 29%'),
                $row('LLG-3', 'Cy', 2500.5, 'Progress Law 29% with ProLaw'),
                $row('LLG-4', 'Dan', 700.0, 'LDR 29%', ['Payment_Date' => '2026-09-03']),   // a September payer
            ]],
            '/WHERE NSF_Date = \?/' => ['success' => true, 'data' => [
                $row('LLG-9', 'Dee', 800.0, 'LDR 29%', ['NSF_Date' => '2026-08-19']),
            ]],
            '/WHERE Cancel_Date = \?/' => ['success' => true, 'data' => []],
        ]);
    }
}
