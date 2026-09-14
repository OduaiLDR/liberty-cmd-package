<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\BusinessDayCalendar;
use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\GenerateEnrollmentBonusReport;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;

/**
 * Jacob 2026-09-14: "EOM Projection (Net) = total / days in the report * days in the month",
 * business days only, skipping weekends and Thanksgiving, Labor Day, July 4 and January 1.
 * His worked example that day: 9 weekdays in 1–13 Sep minus Labor Day = 8, September has 21.
 */
class EnrollmentStatusReportEomProjectionTest extends TestCase
{
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
