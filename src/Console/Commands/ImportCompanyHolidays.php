<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\BusinessDayCalendar;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Loads the company holiday calendar into Azure `dbo.TblCompanyHolidays` — the days the company is
 * closed, which the Enrollment Status Report's EOM projection skips alongside weekends.
 *
 * Jacob 2026-09-15: *"check with CT … they have a calendar of the holidays the company takes to
 * block off and it is extended several years out. See if you can get that and add that to a table
 * and use it for the EOM calculation."*
 *
 * Input is a CSV of `date[,name]`, one holiday per line, header optional:
 *
 *     2026-11-26,Thanksgiving
 *     12/25/2026,Christmas Day
 *
 * Dates parse in ISO or US order (a 4-digit first field is read as ISO). Existing rows for the same
 * date are updated rather than duplicated, so re-importing a corrected file is safe, and nothing is
 * deleted unless --replace-years is given.
 */
class ImportCompanyHolidays extends Command
{
    protected $signature = 'holidays:import
        {file : CSV file of date[,name] — one holiday per line, header optional}
        {--source=CT : Where the calendar came from, stored on each row}
        {--replace-years : Delete existing rows for every year present in the file before inserting (use when CT resend a corrected year)}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import the company holiday calendar into dbo.TblCompanyHolidays (used by the Enrollment Status Report EOM projection).';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");

            return Command::FAILURE;
        }

        try {
            $holidays = $this->parse($path);
        } catch (\Throwable $e) {
            $this->error('Could not parse the file: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($holidays === []) {
            $this->error('No holiday dates found in the file.');

            return Command::FAILURE;
        }

        $years = array_values(array_unique(array_map(static fn (array $h): int => (int) substr($h['date'], 0, 4), $holidays)));
        sort($years);

        $this->info(sprintf('[INFO] Parsed %d holiday date(s) covering %s.', count($holidays), implode(', ', $years)));
        foreach ($holidays as $holiday) {
            $weekend = (int) (new \DateTimeImmutable($holiday['date']))->format('N') >= 6;
            $this->line(sprintf('  %s  %-40s%s', $holiday['date'], $holiday['name'], $weekend ? '  (weekend — no effect on business days)' : ''));
        }

        if ($this->option('dry-run')) {
            $this->warn('[WARN] --dry-run: nothing written.');

            return Command::SUCCESS;
        }

        try {
            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
            $server = $sql->querySqlServer('SELECT @@SERVERNAME AS s')['data'][0]['s'] ?? 'unknown';
            $this->info("[INFO] Writing to {$server}.");

            $this->ensureTable($sql);

            if ($this->option('replace-years')) {
                foreach ($years as $year) {
                    $deleted = $sql->querySqlServer(
                        'DELETE FROM ' . BusinessDayCalendar::TABLE . ' WHERE YEAR(Holiday_Date) = ?',
                        [$year]
                    );
                    $this->info("[INFO] {$year}: removed " . ($deleted['row_count'] ?? 0) . ' existing row(s).');
                }
            }

            $written = 0;
            $source = (string) $this->option('source');
            foreach ($holidays as $holiday) {
                $result = $sql->querySqlServer(
                    'MERGE ' . BusinessDayCalendar::TABLE . ' AS target
                     USING (SELECT CAST(? AS DATE) AS Holiday_Date, CAST(? AS NVARCHAR(100)) AS Holiday_Name, CAST(? AS NVARCHAR(50)) AS Source) AS src
                        ON target.Holiday_Date = src.Holiday_Date
                     WHEN MATCHED THEN UPDATE SET Holiday_Name = src.Holiday_Name, Source = src.Source
                     WHEN NOT MATCHED THEN INSERT (Holiday_Date, Holiday_Name, Source, Created_At)
                          VALUES (src.Holiday_Date, src.Holiday_Name, src.Source, SYSUTCDATETIME());',
                    [$holiday['date'], $holiday['name'], $source]
                );

                if (($result['success'] ?? false) !== true) {
                    $this->error("Failed on {$holiday['date']}: " . ($result['error'] ?? 'unknown error'));

                    return Command::FAILURE;
                }
                $written++;
            }

            $total = $sql->querySqlServer('SELECT COUNT(*) AS n FROM ' . BusinessDayCalendar::TABLE)['data'][0]['n'] ?? '?';
            $this->info("[SUCCESS] {$written} holiday date(s) written; the table now holds {$total}.");
            Log::info('ImportCompanyHolidays: calendar imported.', ['written' => $written, 'years' => $years, 'source' => $source]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            Log::error('ImportCompanyHolidays failed', ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * @return list<array{date: string, name: string}> sorted by date, one row per date
     */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('could not open the file');
        }

        $holidays = [];
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $raw = trim((string) ($row[0] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $date = $this->normalizeDate($raw);
                if ($date === null) {
                    continue;   // header line, or a stray note
                }
                $holidays[$date] = ['date' => $date, 'name' => trim((string) ($row[1] ?? ''))];
            }
        } finally {
            fclose($handle);
        }

        ksort($holidays);

        return array_values($holidays);
    }

    /** ISO when the first field is 4 digits, otherwise US month-first. Null when it is not a date. */
    private function normalizeDate(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $m)) {
            return $this->validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            return $this->validDate((int) $m[3], (int) $m[1], (int) $m[2]);
        }

        // Anything else (e.g. "November 26, 2026") via strtotime, which is lenient but never
        // invents a date from a header like "Date" or "Holiday".
        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function validDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    private function ensureTable(DBConnector $sql): void
    {
        $result = $sql->querySqlServer(
            "IF OBJECT_ID('" . BusinessDayCalendar::TABLE . "', 'U') IS NULL
             CREATE TABLE " . BusinessDayCalendar::TABLE . " (
                 Holiday_Date DATE          NOT NULL,
                 Holiday_Name NVARCHAR(100) NULL,
                 Source       NVARCHAR(50)  NULL,
                 Created_At   DATETIME2     NOT NULL,
                 CONSTRAINT PK_TblCompanyHolidays PRIMARY KEY (Holiday_Date)
             )"
        );

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('could not create ' . BusinessDayCalendar::TABLE . ': ' . ($result['error'] ?? 'unknown error'));
        }
    }
}
