<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use PHPUnit\Framework\TestCase;

/**
 * The eleven holidays the legacy bots skipped, plus the month arithmetic that was
 * overflowing. Dates checked against the published federal calendar, not against
 * the implementation.
 */
class PmodBusinessDateResolverHolidayTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function federalHolidays(): array
    {
        return [
            ['2026-01-01', 'New Year'],
            ['2026-01-19', 'MLK, 3rd Monday January'],
            ['2027-01-18', 'MLK 2027'],
            ['2026-02-16', 'Presidents, 3rd Monday February'],
            ['2026-05-25', 'Memorial, LAST Monday May'],
            ['2027-05-31', 'Memorial 2027, a 5-Monday May'],
            ['2026-06-19', 'Juneteenth'],
            ['2026-07-04', 'Independence Day'],
            ['2025-07-05', 'the hardcoded 2025 case'],
            ['2026-09-07', 'Labor, 1st Monday September'],
            ['2026-10-12', 'Columbus, 2nd Monday October'],
            ['2026-11-11', 'Veterans'],
            ['2026-11-26', 'Thanksgiving, 4th Thursday November'],
            ['2027-11-25', 'Thanksgiving 2027'],
            ['2026-12-25', 'Christmas'],
        ];
    }

    /**
     * @dataProvider federalHolidays
     */
    public function testRecognisesFederalHolidays(string $date, string $label): void
    {
        $this->assertTrue(PmodBusinessDateResolver::isHoliday($date), $label . ' (' . $date . ')');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function ordinaryDays(): array
    {
        return [
            ['2026-01-20', 'the day after MLK'],
            ['2026-05-18', 'the second-to-last Monday of May'],
            ['2026-07-06', 'July 5 is only a holiday in 2025'],
            ['2026-11-19', 'the third Thursday of November'],
            ['2026-12-24', 'Christmas Eve is not a federal holiday'],
            ['2026-03-16', 'March has none'],
        ];
    }

    /**
     * @dataProvider ordinaryDays
     */
    public function testDoesNotInventHolidays(string $date, string $label): void
    {
        $this->assertFalse(PmodBusinessDateResolver::isHoliday($date), $label . ' (' . $date . ')');
    }

    public function testJulyFifthIsOnlyAHolidayIn2025(): void
    {
        $this->assertTrue(PmodBusinessDateResolver::isHoliday('2025-07-05'));
        $this->assertFalse(PmodBusinessDateResolver::isHoliday('2024-07-05'));
        $this->assertFalse(PmodBusinessDateResolver::isHoliday('2026-07-05'));
    }

    public function testNextBusinessDaySkipsHolidays(): void
    {
        // Christmas 2026 is a Friday, so the next available day is Monday the 28th.
        $this->assertSame('2026-12-28', PmodBusinessDateResolver::nextBusinessDay('2026-12-25'));

        // Thanksgiving 2026 is Thursday the 26th; Friday the 27th is not a federal
        // holiday, so that is the next business day.
        $this->assertSame('2026-11-27', PmodBusinessDateResolver::nextBusinessDay('2026-11-26'));

        // New Year's Day 2027 is a Friday.
        $this->assertSame('2027-01-04', PmodBusinessDateResolver::nextBusinessDay('2027-01-01'));
    }

    public function testNextBusinessDayStillSkipsWeekends(): void
    {
        // Saturday 12 September 2026, with no holiday in the way.
        $this->assertSame('2026-09-14', PmodBusinessDateResolver::nextBusinessDay('2026-09-12'));
    }

    /**
     * A weekend running straight into a holiday. Saturday 5 September 2026 used to
     * land on Monday the 7th - which is Labor Day, so the old weekend-only version
     * would have written a draft onto a federal holiday.
     */
    public function testNextBusinessDaySkipsAWeekendFollowedByAHoliday(): void
    {
        $this->assertSame('2026-09-08', PmodBusinessDateResolver::nextBusinessDay('2026-09-05'));
    }

    public function testAnOrdinaryWeekdayIsUnchanged(): void
    {
        $this->assertSame('2026-09-16', PmodBusinessDateResolver::nextBusinessDay('2026-09-16'));
    }

    /** 31 Jan + 1 month was 3 March, because PHP rolls February over. */
    public function testAddMonthsClampsToTheEndOfShortMonths(): void
    {
        $this->assertSame('2026-02-28', PmodBusinessDateResolver::addMonths('2026-01-31', 1));
        $this->assertSame('2028-02-29', PmodBusinessDateResolver::addMonths('2028-01-31', 1));
        $this->assertSame('2026-04-30', PmodBusinessDateResolver::addMonths('2026-03-31', 1));
        $this->assertSame('2026-06-30', PmodBusinessDateResolver::addMonths('2026-05-31', 1));
    }

    public function testAddMonthsKeepsTheDayWhereItExists(): void
    {
        $this->assertSame('2026-10-16', PmodBusinessDateResolver::addMonths('2026-09-16', 1));
        $this->assertSame('2027-09-16', PmodBusinessDateResolver::addMonths('2026-09-16', 12));
        $this->assertSame('2026-12-31', PmodBusinessDateResolver::addMonths('2026-10-31', 2));
    }

    public function testAddMonthsCrossesTheYearBoundary(): void
    {
        $this->assertSame('2027-01-15', PmodBusinessDateResolver::addMonths('2026-11-15', 2));
        $this->assertSame('2027-02-28', PmodBusinessDateResolver::addMonths('2026-12-31', 2));
    }

    public function testDraftModificationCutoffIsUnaffected(): void
    {
        $this->assertSame('2026-09-04', PmodBusinessDateResolver::draftModificationCutoff('2026-09-02'));
    }
}
