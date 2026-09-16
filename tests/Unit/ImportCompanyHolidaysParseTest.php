<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ImportCompanyHolidays;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * CT's first calendar (15 Sep 2026) was a raw dump of the portal's holiday_dates table —
 * `id,holiday_date,holiday_name,created_at,updated_at` — not the `date,name` file the command was
 * written for. The parser must take either, and must say when a year it covers is missing a
 * weekday holiday the built-in rule would have skipped (that file had no 1 Jan 2027).
 */
class ImportCompanyHolidaysParseTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    private BufferedOutput $output;

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function test_cts_raw_portal_export_loads_as_sent(): void
    {
        $holidays = $this->parse(<<<'CSV'
            "id","holiday_date","holiday_name","created_at","updated_at"
            "31","2026-01-01","New Year Day","2023-03-09 20:33:14","2023-03-09 20:33:14"
            "32","2026-01-01","New Year Day","2023-03-09 20:33:14","2023-03-09 20:33:14"
            "49","2026-11-26","Thanksgiving Day","2023-03-09 20:33:14","2023-03-09 20:33:14"
            "43","2026-09-07","Labor Day","2023-03-09 20:33:14","2023-03-09 20:33:14"
            CSV);

        $this->assertSame([
            ['date' => '2026-01-01', 'name' => 'New Year Day'],
            ['date' => '2026-09-07', 'name' => 'Labor Day'],
            ['date' => '2026-11-26', 'name' => 'Thanksgiving Day'],
        ], $holidays, 'dates from holiday_date not id, sorted, the duplicated 1 Jan collapsed to one row');

        $this->assertStringContainsString('dates from "holiday_date", names from "holiday_name"', $this->output->fetch());
    }

    public function test_a_headerless_positional_file_still_works(): void
    {
        $holidays = $this->parse("2026-11-26,Thanksgiving\n12/25/2026,Christmas Day\n");

        $this->assertSame([
            ['date' => '2026-11-26', 'name' => 'Thanksgiving'],
            ['date' => '2026-12-25', 'name' => 'Christmas Day'],
        ], $holidays);
        $this->assertSame('', $this->output->fetch(), 'no header, nothing to announce');
    }

    public function test_the_date_column_is_never_an_audit_timestamp(): void
    {
        // created_at comes first and would parse as a date; holiday_date must still win.
        $holidays = $this->parse("created_at,holiday_date,holiday_name\n2023-03-09 20:33:14,2026-12-25,Christmas\n");

        $this->assertSame([['date' => '2026-12-25', 'name' => 'Christmas']], $holidays);
    }

    public function test_a_generic_header_and_a_file_with_no_name_column(): void
    {
        $this->assertSame(
            [['date' => '2026-12-25', 'name' => 'Christmas']],
            $this->parse("Date,Holiday\n12/25/2026,Christmas\n")
        );

        $this->assertSame(
            [['date' => '2026-12-25', 'name' => '']],
            $this->parse("Date\n2026-12-25\n")
        );
        $this->assertStringContainsString('names from (no name column)', $this->output->fetch());
    }

    public function test_a_covered_year_missing_a_built_in_weekday_holiday_is_flagged(): void
    {
        // CT's file: 2027 has everything except New Year's Day. Once 2027 is in the table the
        // built-in rule no longer applies to it, so 1 Jan 2027 (a Friday) would be a business day.
        $gaps = $this->gaps([
            ['date' => '2027-01-18', 'name' => 'MLK'],
            ['date' => '2027-09-06', 'name' => 'Labor Day'],
            ['date' => '2027-11-25', 'name' => 'Thanksgiving Day'],
            // 2027-07-04 is a Sunday: not listed, and must NOT be flagged.
        ], [2027]);

        $this->assertCount(1, $gaps);
        $this->assertStringContainsString('2027-01-01 (Friday) is not in the file', $gaps[0]);
    }

    public function test_a_complete_year_produces_no_gap_warning(): void
    {
        $gaps = $this->gaps([
            ['date' => '2026-01-01', 'name' => 'New Year Day'],
            ['date' => '2026-09-07', 'name' => 'Labor Day'],
            ['date' => '2026-11-26', 'name' => 'Thanksgiving Day'],
            // 2026-07-04 is a Saturday: absent and irrelevant.
        ], [2026]);

        $this->assertSame([], $gaps);
    }

    /**
     * @return list<array{date: string, name: string}>
     */
    private function parse(string $csv): array
    {
        $file = tempnam(sys_get_temp_dir(), 'hol');
        $this->tempFiles[] = $file;
        // Heredocs above are indented for readability; the file must not be.
        file_put_contents($file, preg_replace('/^[ \t]+/m', '', $csv));

        $command = $this->command();
        $method = new ReflectionMethod($command, 'parse');

        return $method->invoke($command, $file);
    }

    /**
     * @param list<array{date: string, name: string}> $holidays
     * @param list<int> $years
     * @return list<string>
     */
    private function gaps(array $holidays, array $years): array
    {
        $command = $this->command();
        $method = new ReflectionMethod($command, 'builtInGaps');

        return $method->invoke($command, $holidays, $years);
    }

    private function command(): ImportCompanyHolidays
    {
        $command = new ImportCompanyHolidays();
        $this->output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }
}
