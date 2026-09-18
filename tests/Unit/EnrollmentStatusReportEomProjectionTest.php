<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\BusinessDayCalendar;
use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\GenerateEnrollmentBonusReport;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;

/**
 * Jacob 2026-09-14: "EOM Projection (Net) = total / days in the report * days in the month",
 * business days only, skipping weekends and Thanksgiving, Labor Day, July 4 and January 1.
 * His worked example that day: 9 weekdays in 1–13 Sep minus Labor Day = 8, September has 21.
 */
class EnrollmentStatusReportEomProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The calendar holds its loaded dates statically, so one test must never leak into the next.
        BusinessDayCalendar::reset();

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
        BusinessDayCalendar::reset();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_jacobs_september_2026_example_is_8_of_21(): void
    {
        $this->assertSame(8, BusinessDayCalendar::countBetween('2026-09-01', '2026-09-13'));
        $this->assertSame(21, BusinessDayCalendar::countInMonth('2026-09-01'));
    }

    public function test_the_four_holidays(): void
    {
        $holiday = static fn (string $d): bool => BusinessDayCalendar::isHoliday(new \DateTimeImmutable($d));

        $this->assertTrue($holiday('2026-09-07'), 'Labor Day 2026');
        $this->assertTrue($holiday('2027-09-06'), 'Labor Day 2027');
        $this->assertTrue($holiday('2026-11-26'), 'Thanksgiving 2026 (4th == last Thursday)');
        $this->assertTrue($holiday('2028-11-23'), 'Thanksgiving 2028 is the 4th Thursday');
        $this->assertFalse($holiday('2028-11-30'), '…not the 5th/last Thursday of Nov 2028');
        $this->assertTrue($holiday('2026-07-04'));
        $this->assertTrue($holiday('2027-01-01'));
        $this->assertFalse($holiday('2026-12-25'), 'Christmas is deliberately not in this calendar');
        $this->assertFalse($holiday('2026-05-25'), 'Memorial Day is deliberately not in this calendar');
    }

    public function test_weekend_holidays_are_not_shifted_to_a_weekday(): void
    {
        // July 4 2026 is a Saturday: not a business day already, and Friday July 3 stays one.
        $this->assertFalse(BusinessDayCalendar::isBusinessDay(new \DateTimeImmutable('2026-07-04')));
        $this->assertTrue(BusinessDayCalendar::isBusinessDay(new \DateTimeImmutable('2026-07-03')));
        $this->assertSame(23, BusinessDayCalendar::countInMonth('2026-07-01'), '23 weekdays, nothing deducted');

        // January 1 2027 is a Friday: a real holiday that month.
        $this->assertSame(20, BusinessDayCalendar::countInMonth('2027-01-01'));
    }

    /**
     * Jacob 2026-09-15: CT keep the company's own closure calendar, several years out. When the
     * table covers a year it is the authority for that year — including days the built-in rule
     * never knew about (Christmas, the day after Thanksgiving, a company shutdown week).
     */
    public function test_the_company_calendar_replaces_the_built_in_rule_for_the_years_it_covers(): void
    {
        $loaded = BusinessDayCalendar::loadFromDatabase($this->connectorWith([
            ['Holiday_Date' => '2026-11-26'],   // Thanksgiving
            ['Holiday_Date' => '2026-11-27'],   // day after — the built-in rule does not have this
            ['Holiday_Date' => '2026-12-25'],   // Christmas — nor this
        ]));

        $this->assertSame(3, $loaded['loaded']);
        $this->assertSame([2026], $loaded['years']);
        $this->assertNull($loaded['error']);

        $holiday = static fn (string $d): bool => BusinessDayCalendar::isHoliday(new \DateTimeImmutable($d));
        $this->assertTrue($holiday('2026-11-27'), 'a CT holiday the built-in rule does not know');
        $this->assertTrue($holiday('2026-12-25'));
        $this->assertFalse($holiday('2026-09-07'), 'Labor Day is NOT in this calendar, so 2026 no longer treats it as one');

        // November 2026: 21 weekdays, minus Thanksgiving and the day after.
        $this->assertSame(19, BusinessDayCalendar::countInMonth('2026-11-01'));
    }

    public function test_a_year_the_calendar_does_not_cover_falls_back_to_the_built_in_rule(): void
    {
        BusinessDayCalendar::loadFromDatabase($this->connectorWith([['Holiday_Date' => '2026-12-25']]));

        $this->assertSame([2026], BusinessDayCalendar::loadedYears());
        // 2027 has no rows: the built-in four still apply, so the divisor is never silently inflated.
        $this->assertTrue(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2027-01-01')));
        $this->assertTrue(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2027-09-06')), 'Labor Day 2027');
        $this->assertSame(20, BusinessDayCalendar::countInMonth('2027-01-01'));
        // …while 2026 uses the table, which does not list New Year's Day.
        $this->assertFalse(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2026-01-01')));
    }

    public function test_an_empty_or_unreadable_table_keeps_the_built_in_rule(): void
    {
        $empty = BusinessDayCalendar::loadFromDatabase($this->connectorWith([]));
        $this->assertSame(['loaded' => 0, 'years' => [], 'error' => null], $empty);
        $this->assertTrue(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2026-09-07')), 'empty table must not mean "no holidays"');
        $this->assertSame(21, BusinessDayCalendar::countInMonth('2026-09-01'));

        $broken = BusinessDayCalendar::loadFromDatabase(new RecordingSqlServerConnector([
            '/TblCompanyHolidays/' => ['success' => false, 'error' => 'Invalid object name', 'data' => []],
        ]));
        $this->assertSame('Invalid object name', $broken['error']);
        $this->assertTrue(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2026-09-07')));
    }

    public function test_the_calendar_is_read_from_the_named_table_and_reset_forgets_it(): void
    {
        $connector = $this->connectorWith([['Holiday_Date' => '2026-12-25']]);
        BusinessDayCalendar::loadFromDatabase($connector);

        $this->assertSame('dbo.TblCompanyHolidays', BusinessDayCalendar::TABLE);
        $this->assertStringContainsString('FROM dbo.TblCompanyHolidays', $connector->calls[0]['sql']);

        BusinessDayCalendar::reset();
        $this->assertSame([], BusinessDayCalendar::loadedYears());
        $this->assertFalse(BusinessDayCalendar::isHoliday(new \DateTimeImmutable('2026-12-25')), 'back to the built-in rule');
    }

    public function test_count_between_is_inclusive_and_zero_when_reversed(): void
    {
        $this->assertSame(1, BusinessDayCalendar::countBetween('2026-09-14', '2026-09-14'));
        $this->assertSame(0, BusinessDayCalendar::countBetween('2026-09-12', '2026-09-13'), 'a weekend');
        $this->assertSame(0, BusinessDayCalendar::countBetween('2026-09-14', '2026-09-13'));
    }

    public function test_summary_labels_and_eom_projection(): void
    {
        $rows = [
            $this->enrolled('LDR', 8000.0),
            $this->enrolled('PLAW', 3000.0),
            ['SNOWFLAKE_SOURCE' => 'LDR', 'DEBT_AMOUNT' => 500.0, 'AZURE_STATUS' => 'Dropped / Cancelled', 'ENROLLED' => false],
        ];
        $pending = [['SOURCE' => 'LDR', 'ENROLLED_DEBT' => 2000.0]];

        $summary = $this->buildSummary($rows, $pending, '2026-09-01', '2026-09-13');

        $this->assertSame(
            ['All Enrollments (Gross)', 'Enrolled', 'Cancels', 'Reconsideration Pending', 'At-Risk', 'Pending', 'Projected (Net)', 'EOM Projection (Net)'],
            array_keys($summary['Combined']),
            'row order on the sheet and in the email'
        );

        $this->assertSame(11500.0, $summary['Combined']['All Enrollments (Gross)']);
        $this->assertSame(11000.0, $summary['Combined']['Enrolled']);
        $this->assertSame(500.0, $summary['Combined']['Cancels']);
        $this->assertSame(2000.0, $summary['Combined']['Pending']);
        $this->assertSame(13000.0, $summary['Combined']['Projected (Net)']);

        // x / 8 * 21, per column.
        $this->assertEqualsWithDelta(13000.0 / 8 * 21, $summary['Combined']['EOM Projection (Net)'], 0.001);
        $this->assertEqualsWithDelta(10000.0 / 8 * 21, $summary['LDR']['EOM Projection (Net)'], 0.001);
        $this->assertEqualsWithDelta(3000.0 / 8 * 21, $summary['Progress Law']['EOM Projection (Net)'], 0.001);
    }

    public function test_a_full_previous_month_projects_to_itself(): void
    {
        // Days 1–6 report the previous month in full: days in report == days in month => factor 1.
        $summary = $this->buildSummary([$this->enrolled('LDR', 1000.0)], [], '2026-08-01', '2026-08-31');

        $this->assertSame(1000.0, $summary['Combined']['Projected (Net)']);
        $this->assertEqualsWithDelta(1000.0, $summary['Combined']['EOM Projection (Net)'], 0.001);
    }

    public function test_no_business_days_yet_yields_zero_not_a_division_error(): void
    {
        $summary = $this->buildSummary([$this->enrolled('LDR', 1000.0)], [], '2026-09-05', '2026-09-06');

        $this->assertSame(0.0, $summary['Combined']['EOM Projection (Net)']);
    }

    /**
     * @param list<array{Holiday_Date: string}> $rows
     */
    private function connectorWith(array $rows): RecordingSqlServerConnector
    {
        return new RecordingSqlServerConnector([
            '/TblCompanyHolidays/' => ['success' => true, 'data' => $rows, 'row_count' => count($rows)],
        ]);
    }

    /** @return array<string, mixed> */
    private function enrolled(string $source, float $debt): array
    {
        return ['SNOWFLAKE_SOURCE' => $source, 'DEBT_AMOUNT' => $debt, 'AZURE_STATUS' => $source === 'PLAW' ? 'ProLaw Enrolled' : 'LDR Enrolled', 'ENROLLED' => true];
    }

    private function buildSummary(array $rows, array $pending, string $from, string $to): array
    {
        $command = new GenerateEnrollmentBonusReport();
        $method = new ReflectionMethod($command, 'buildSummary');

        return $method->invoke($command, $rows, $pending, $from, $to);
    }
}
