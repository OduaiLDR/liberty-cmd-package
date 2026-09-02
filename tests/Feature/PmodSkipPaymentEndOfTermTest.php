<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use PHPUnit\Framework\TestCase;

/**
 * The end-of-term date arithmetic behind Skip Payment (§7.4).
 *
 * SkipPaymentAction itself needs a gateway and Laravel's container, so what is
 * pinned here is the rule the fix relies on: a skipped payment lands a month
 * AFTER the last draft, slid off weekends and holidays, and several skipped
 * payments step a month apart rather than stacking on one date.
 */
class PmodSkipPaymentEndOfTermTest extends TestCase
{
    /** @return list<string> */
    private function endOfTermDates(string $lastDraftDate, int $skipCount): array
    {
        $dates = [];

        for ($offset = 0; $offset < $skipCount; $offset++) {
            $dates[] = PmodBusinessDateResolver::nextBusinessDay(
                PmodBusinessDateResolver::addMonths($lastDraftDate, $offset + 1),
            );
        }

        return $dates;
    }

    /** The collision: the skipped payment must not reuse the last draft's own date. */
    public function testASkippedPaymentLandsAfterTheLastDraft(): void
    {
        $lastDraft = '2027-03-16';
        $dates = $this->endOfTermDates($lastDraft, 1);

        $this->assertNotSame($lastDraft, $dates[0]);
        $this->assertGreaterThan($lastDraft, $dates[0]);
        $this->assertSame('2027-04-16', $dates[0]);
    }

    /** Several skips must not all land on the same day - that is the same bug, N times. */
    public function testMultipleSkippedPaymentsStepAMonthApart(): void
    {
        $dates = $this->endOfTermDates('2027-03-16', 3);

        $this->assertSame(['2027-04-16', '2027-05-17', '2027-06-16'], $dates);
        $this->assertSame($dates, array_values(array_unique($dates)));
    }

    /** 2027-05-16 is a Sunday, so the second one slides to Monday the 17th. */
    public function testEndOfTermDatesAvoidWeekends(): void
    {
        $this->assertSame('2027-05-17', $this->endOfTermDates('2027-03-16', 2)[1]);
    }

    /** Last draft 30 Nov 2026 -> 25 Dec is Christmas -> next business day. */
    public function testEndOfTermDatesAvoidHolidays(): void
    {
        $this->assertSame('2026-12-28', $this->endOfTermDates('2026-11-25', 1)[0]);
    }

    /**
     * A 31st anchor must not overflow into the month AFTER next: 31 Jan 2027
     * clamps to 28 Feb, not to 3 March as `+1 month` would give.
     *
     * 28 Feb 2027 is a Sunday, so it then slides forward to Monday 1 March. That
     * crosses the month boundary, but only by a day and only ever forwards -
     * sliding backwards would move a draft EARLIER than the client agreed, and
     * the VBA never did it either (`d = d + 1`).
     */
    public function testEndOfTermClampsToMonthEndBeforeSliding(): void
    {
        $this->assertSame('2027-02-28', PmodBusinessDateResolver::addMonths('2027-01-31', 1));
        $this->assertSame('2027-03-01', $this->endOfTermDates('2027-01-31', 1)[0]);
    }
}
