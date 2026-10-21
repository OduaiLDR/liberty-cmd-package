<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Closure;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmsLegacyHistoryFiles;
use SmsProspectReconciliation;

require_once dirname(__DIR__, 2).'/scripts/sms-legacy-history-files.php';

final class SmsLegacyHistoryFilesTest extends TestCase
{
    private const HEADER = 'First name,address,debt load,phone1,phone2,phone3,phone4,phone5,send date';
    private const DATE = '2026-10-08';
    private string $directory;
    private array $files = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sms-history-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_link($file) || file_exists($file)) self::assertTrue(unlink($file));
        }
        self::assertTrue(rmdir($this->directory));
    }

    private function file(string $bytes, string $name = ''): string
    {
        $path = $this->directory.'/'.($name !== '' ? $name : 'part-'.count($this->files).'.csv');
        $this->files[] = $path;
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        return $path;
    }

    private function record(array $phones = ['2025550101'], string $name = 'Synthetic Person', string $date = self::DATE): string
    {
        $handle = fopen('php://memory', 'w+b');
        fputcsv($handle, [$name, 'Synthetic address', '10000', ...array_pad($phones, 5, ''), $date], ',', '"', '', "\n");
        rewind($handle); $record = stream_get_contents($handle); fclose($handle);
        return $record;
    }

    private function fixture(?PDO $db = null): array
    {
        $db ??= new PDO('sqlite::memory:');
        $paths = [$this->file(self::HEADER."\r\n".$this->record(['(202) 555-0101', '+12025550102', '', '', '0015550103'])
                .$this->record(['', '202.555.0101', '1 202 555 0104'])),
            $this->file(self::HEADER."\n".$this->record(['', '2025550102', '202-555-0105', '2025550106'])),
            $this->file(self::HEADER."\n".$this->record(['', '', '', '2025550106', '2025550107'])
                .$this->record(['2025550107', '2025550107']))];
        return [$db, $paths, SmsLegacyHistoryFiles::build($db, $paths, self::DATE)];
    }

    private function refused(Closure $operation, string $kind): void
    {
        try { $operation(); self::fail('Expected evidence refusal.'); }
        catch (RuntimeException $error) {
            self::assertMatchesRegularExpression('/\AHistorical SMS evidence '.$kind.' refused(?: \(part [0-9]+, row [0-9]+\))?\.\z/', $error->getMessage());
            self::assertNull($error->getPrevious());
            foreach ([$this->directory, 'Synthetic', '2025550101', self::DATE] as $private) {
                self::assertStringNotContainsString($private, $error->getMessage());
            }
        }
    }

    public function test_three_parts_all_five_slots_deduplicate_and_produce_stable_raw_and_event_proof(): void
    {
        [$db, $paths, $proof] = $this->fixture();
        $expectedLines = '';
        foreach (['2025550101', '2025550102', '0015550103', '2025550104', '2025550105', '2025550106', '2025550107'] as $index => $phone) {
            $expectedLines .= '['.($index + 1).',"'.$phone.'","2026-10-08"]'."\n";
        }
        $files = [];
        foreach ($paths as $index => $path) {
            $files[] = ['part' => $index + 1, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path),
                'rows' => [2, 1, 2][$index], 'phone_occurrences' => [5, 3, 4][$index]];
        }
        self::assertSame(['format' => 1, 'complete' => true, 'policy' => 'verified-phone-day-csv-v1',
            'sent_date' => self::DATE, 'files' => $files, 'rows' => 5, 'phone_occurrences' => 12,
            'unique_events' => 7, 'duplicate_events' => 5, 'event_max_id' => 7,
            'events_sha256' => hash('sha256', $expectedLines)], $proof);
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($db));
        self::assertSame($proof, SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), $paths, self::DATE));
        self::assertSame(['Event_ID' => 3, 'Phone' => '0015550103', 'Send_Date' => self::DATE], SmsLegacyHistoryFiles::page($db, 2, 1)[0]);
        $payload = $db->query('SELECT payload FROM state')->fetchColumn();
        foreach ([$this->directory, 'Synthetic', 'address', '10000'] as $discarded) self::assertStringNotContainsString($discarded, $payload);
        self::assertSame(['id', 'phone', 'send_date'], array_column($db->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
    }

    public function test_bom_case_spacing_multiline_escaped_quotes_and_final_record_without_newline(): void
    {
        $header = "\xEF\xBB\xBF\" FIRST  Name \", ADDRESS ,Debt Load, Phone 1 ,PHONE2,phone3,phone4,phone5, Send DATE \r\n";
        $raw = $header.rtrim($this->record(['+1 (202) 555-0101'], "Synthetic, \"quoted\"\r\nname"), "\n");
        $path = $this->file($raw); $before = [fileperms($path), filemtime($path), hash_file('sha256', $path)];
        $proof = SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], self::DATE);
        self::assertSame(1, $proof['rows']); self::assertSame(strlen($raw), $proof['files'][0]['bytes']);
        self::assertSame(hash('sha256', $raw), $proof['files'][0]['sha256']);
        clearstatcache(true, $path);
        self::assertSame($before, [fileperms($path), filemtime($path), hash_file('sha256', $path)]);
    }

    public static function validPhones(): array
    {
        return array_map(static fn ($phone) => [$phone], ['2025550101', '12025550101', '+12025550101',
            '202-555-0101', '202.555.0101', '202 555 0101', '(202)5550101', '(202) 555-0101',
            '1-202-555-0101', '+1 202 555 0101', '1 (202) 555-0101', " \t2025550101\t "]);
    }

    #[DataProvider('validPhones')]
    public function test_accepted_us_formatting(string $phone): void
    {
        $db = new PDO('sqlite::memory:');
        SmsLegacyHistoryFiles::build($db, [$this->file(self::HEADER."\n".$this->record([$phone]))], self::DATE);
        self::assertSame('2025550101', SmsLegacyHistoryFiles::page($db, 0)[0]['Phone']);
    }

    public static function badCsv(): array
    {
        $header = self::HEADER."\n";
        $row = 'Synthetic Person,Synthetic address,10000,2025550101,,,,,2026-10-08';
        $cases = [
            'empty file' => '', 'header only' => $header,
            'missing header' => $row."\n", 'extra header' => self::HEADER.",other\n".$row,
            'wrong header' => str_replace('phone5', 'phone4', $header).$row,
            'reordered header' => str_replace('phone1,phone2', 'phone2,phone1', $header).$row,
            'underscore header' => str_replace('send date', 'send_date', $header).$row,
            'BOM elsewhere' => str_replace('address', "\xEF\xBB\xBFaddress", $header).$row,
            'few fields' => $header.'Synthetic,address,2025550101,2026-10-08',
            'extra field' => $header.$row.',extra',
            'blank record' => $header."\n", 'empty fields' => $header.',,,,,,,,',
            'no phone' => $header.'Synthetic,address,10000,,,,,,2026-10-08',
            'trailing blank record' => $header.$row."\n\n",
            'trailing short record' => $header.$row."\nSynthetic,address",
            'trailing unfinished quote' => $header.$row."\n\"unfinished",
            'open quote' => $header.'"'.$row,
            'bare quote' => $header.str_replace('Synthetic Person', 'Syn"thetic', $row),
            'closing quote garbage' => $header.str_replace('Synthetic Person', '"Synthetic"x', $row),
            'backslash quote escape' => $header.str_replace('Synthetic Person', '"Syn\"thetic"', $row),
            'bare CR' => $header.$row."\r",
            'NUL' => $header.str_replace('Synthetic', "Syn\0thetic", $row),
        ];
        foreach (['', '2026-10-07', '2026-10-09', '2026-02-29', '2026-13-01', '2026-10-32', '0000-10-08',
            '2026-1-08', '10/08/2026', '2026-10-08T00:00:00Z', ' 2026-10-08', '2026-10-08 '] as $date) {
            $cases['date '.$date] = $header.str_replace(self::DATE, $date, $row);
        }
        return array_map(static fn ($raw) => [$raw], $cases);
    }

    #[DataProvider('badCsv')]
    public function test_invalid_records_never_publish_or_silently_skip(string $raw): void
    {
        $db = new PDO('sqlite::memory:');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$this->file($raw)], self::DATE), 'build');
        self::assertSame(0, $db->query('SELECT complete FROM state')->fetchColumn());
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
    }

    public static function badPhones(): array
    {
        return array_map(static fn ($phone) => [$phone], ['call2025550101', '2025550101 ext 2', "2025550101\n",
            '202555010', '20255501010', '22025550101', '+442025550101', '+2025550101', '++12025550101',
            '202--555-0101', '(2025550101)', '202.555-0101', '2025550101;', "2025550101');DROP TABLE events;--", '２０２５５５０１０１']);
    }

    #[DataProvider('badPhones')]
    public function test_every_nonempty_phone_slot_must_be_usable(string $bad): void
    {
        for ($slot = 0; $slot < 5; $slot++) {
            $phones = array_fill(0, 5, '2025550101'); $phones[$slot] = $bad;
            $db = new PDO('sqlite::memory:');
            $path = $this->file(self::HEADER."\n".$this->record($phones));
            $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$path], self::DATE), 'build');
        }
    }

    public static function invalidExpectedDates(): array
    {
        return [['2026-02-29'], ['2024-02-30'], ['0000-01-01'], ['2026-04-31'], ['2026-00-01'], ['2026-10-8'], ['2026-10-08 ']];
    }

    #[DataProvider('invalidExpectedDates')]
    public function test_expected_date_must_itself_be_an_iso_calendar_date(string $date): void
    {
        $db = new PDO('sqlite::memory:');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$this->file(self::HEADER."\n".$this->record([], 'Synthetic', $date))], $date), 'build');
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn());
    }

    public function test_leap_day_calendar_date_is_valid(): void
    {
        $path = $this->file(self::HEADER."\n".$this->record(['2025550101'], 'Synthetic', '2024-02-29'));
        self::assertSame('2024-02-29', SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], '2024-02-29')['sent_date']);
    }

    public function test_one_empty_part_refuses_the_whole_build(): void
    {
        $db = new PDO('sqlite::memory:');
        $paths = [$this->file(self::HEADER."\n".$this->record()), $this->file(self::HEADER."\n")];
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, $paths, self::DATE), 'build');
        self::assertSame(0, $db->query('SELECT complete FROM state')->fetchColumn());
    }

    public function test_ignored_columns_are_opaque_and_sql_payloads_never_become_commands_or_metadata(): void
    {
        $db = new PDO('sqlite::memory:'); $name = "=HYPERLINK(\"https://example.invalid\"); DROP TABLE events; --";
        $path = $this->file(self::HEADER."\n".$this->record(['2025550101'], $name), "part');--.csv");
        SmsLegacyHistoryFiles::build($db, [$path], self::DATE);
        self::assertCount(1, SmsLegacyHistoryFiles::page($db, 0));
        self::assertStringNotContainsString($name, $db->query('SELECT payload FROM state')->fetchColumn());
    }

    public function test_exact_record_byte_limit_is_allowed_and_larger_single_and_multiline_records_refuse(): void
    {
        $base = $this->record(['2025550101'], '');
        $record = $this->record(['2025550101'], str_repeat('a', SmsLegacyHistoryFiles::MAX_ROW_BYTES - strlen($base)));
        self::assertSame(SmsLegacyHistoryFiles::MAX_ROW_BYTES, strlen($record));
        self::assertSame(1, SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$this->file(self::HEADER."\n".$record)], self::DATE)['rows']);
        foreach (['a'.$record, $this->record(['2025550101'], str_repeat("a\n", 524288))] as $tooLarge) {
            $db = new PDO('sqlite::memory:');
            $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$this->file(self::HEADER."\n".$tooLarge)], self::DATE), 'build');
        }
    }

    public function test_replay_and_existing_schema_are_refused_without_altering_completed_evidence(): void
    {
        [$db, $paths, $proof] = $this->fixture();
        $before = $db->query('SELECT total_changes()')->fetchColumn();
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, $paths, self::DATE), 'build');
        self::assertSame($before, $db->query('SELECT total_changes()')->fetchColumn());
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($db));
        $other = new PDO('sqlite::memory:'); $other->exec('CREATE TABLE unrelated(id INTEGER)');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($other, $paths, self::DATE), 'build');
        self::assertSame(['unrelated'], $other->query('SELECT name FROM sqlite_master')->fetchAll(PDO::FETCH_COLUMN));
        $other = new PDO('sqlite::memory:'); $other->beginTransaction();
        $this->refused(fn () => SmsLegacyHistoryFiles::build($other, $paths, self::DATE), 'build');
        self::assertTrue($other->inTransaction()); $other->rollBack();
    }

    public function test_bounded_commits_survive_a_late_error_but_never_become_apply_eligible(): void
    {
        $db = new SmsHistoryTestSqlite();
        $raw = self::HEADER."\n";
        for ($i = 0; $i < SmsLegacyHistoryFiles::COMMIT_EVERY + 3; $i++) $raw .= $this->record([sprintf('202555%04d', $i)]);
        $path = $this->file($raw.'truncated');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$path], self::DATE), 'build');
        self::assertSame([0, 5000], $db->committedEventCounts);
        self::assertSame(5000, $db->query('SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertSame(['complete' => 0, 'payload' => null], $db->query('SELECT complete,payload FROM state')->fetch(PDO::FETCH_ASSOC));
        self::assertFalse($db->inTransaction());
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, [$path], self::DATE), 'build');
    }

    public function test_default_and_maximum_pages_are_read_only_and_keyset_ordered(): void
    {
        $db = new PDO('sqlite::memory:'); $raw = self::HEADER."\n";
        for ($i = 0; $i < 1105; $i++) $raw .= $this->record([sprintf('202555%04d', $i)]);
        $proof = SmsLegacyHistoryFiles::build($db, [$this->file($raw)], self::DATE);
        $before = $db->query('SELECT total_changes()')->fetchColumn();
        $db->exec('PRAGMA query_only=ON');
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($db));
        self::assertCount(250, SmsLegacyHistoryFiles::page($db, 0));
        self::assertCount(1000, SmsLegacyHistoryFiles::page($db, 0, 1000));
        $page = SmsLegacyHistoryFiles::page($db, 1000, 1000);
        self::assertSame(range(1001, 1105), array_column($page, 'Event_ID'));
        self::assertSame([], SmsLegacyHistoryFiles::page($db, 1105));
        self::assertSame([], SmsLegacyHistoryFiles::page($db, 2147483647));
        self::assertSame($before, $db->query('SELECT total_changes()')->fetchColumn());
        self::assertFalse($db->inTransaction());
    }

    public static function invalidRanges(): array
    {
        return [[-1, 1], [2147483648, 1], [0, 0], [0, -1], [0, 1001]];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_page_ranges_refuse(int $after, int $size): void
    {
        [$db] = $this->fixture();
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, $after, $size), 'page');
    }

    public function test_closed_artifact_does_not_need_originals_and_can_be_opened_read_only(): void
    {
        $journal = $this->directory.'/journal.sqlite'; $this->files[] = $journal;
        [$db, $paths, $proof] = $this->fixture(new PDO('sqlite:'.$journal)); $db = null;
        foreach ($paths as $path) self::assertTrue(unlink($path));
        $reader = new PDO('sqlite:file:'.str_replace('\\', '/', $journal).'?mode=ro');
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($reader));
        self::assertCount(7, SmsLegacyHistoryFiles::page($reader, 0)); $reader = null;
    }

    public static function eventCorruption(): array
    {
        return [["UPDATE events SET phone='call2025550101' WHERE id=1"],
            ["UPDATE events SET phone='2025550199' WHERE id=1"],
            ["UPDATE events SET phone=CAST('2025550101' AS BLOB) WHERE id=1"],
            ["UPDATE events SET send_date='2026-02-29' WHERE id=1"],
            ["UPDATE events SET send_date=CAST('2026-10-08' AS BLOB) WHERE id=1"],
            ['DELETE FROM events WHERE id=2'], ['UPDATE events SET id=99 WHERE id=1'],
            ['UPDATE events SET id=2147483648 WHERE id=1'], ['UPDATE events SET id=-1 WHERE id=1'],
            ["INSERT INTO events VALUES(99,'2025550199','2026-10-08')"]];
    }

    #[DataProvider('eventCorruption')]
    public function test_proof_recomputes_events_and_rejects_modified_journal(string $sql): void
    {
        [$db] = $this->fixture(); $db->exec($sql);
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
    }

    public function test_pages_validate_stored_types_completion_and_event_shape(): void
    {
        foreach (["UPDATE events SET phone=CAST('2025550101' AS BLOB) WHERE id=1",
            "UPDATE events SET phone='20255a0101' WHERE id=1", "UPDATE events SET send_date='2026-10-09' WHERE id=1",
            'UPDATE state SET complete=0', 'UPDATE state SET payload=NULL'] as $sql) {
            [$db] = $this->fixture(); $db->exec($sql);
            $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
        }
        [$db] = $this->fixture(); $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
    }

    public function test_proof_and_pages_refuse_malformed_metadata_and_weaker_schema(): void
    {
        foreach (['format', 'complete', 'policy', 'rows', 'phone_occurrences', 'unique_events', 'duplicate_events',
            'event_max_id', 'events_sha256', 'sent_date', 'files'] as $key) {
            [$db, , $proof] = $this->fixture(); $proof[$key] = null;
            $db->prepare('UPDATE state SET payload=?')->execute([SmsProspectReconciliation::json($proof)]);
            $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
            $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
        }
        [$db] = $this->fixture();
        $db->exec('ALTER TABLE events RENAME TO original_events');
        $db->exec('CREATE TABLE events(id,phone,send_date)');
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
    }

    public function test_missing_journal_and_temporary_table_shadowing_refuse_without_customer_errors(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
        self::assertSame(PDO::ERRMODE_SILENT, $db->getAttribute(PDO::ATTR_ERRMODE));
        [$db] = $this->fixture(); $db->exec('CREATE TEMP TABLE events(id,phone,send_date)');
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 0), 'page');
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
    }

    public function test_actual_maximum_is_used_even_when_valid_ids_have_gaps(): void
    {
        [$db, , $proof] = $this->fixture();
        $db->exec('UPDATE events SET id=id+100');
        $lines = '';
        foreach ($db->query('SELECT id,phone,send_date FROM events ORDER BY id')->fetchAll(PDO::FETCH_NUM) as $event) {
            $lines .= json_encode($event, JSON_THROW_ON_ERROR)."\n";
        }
        $proof['event_max_id'] = 107; $proof['events_sha256'] = hash('sha256', $lines);
        $db->prepare('UPDATE state SET payload=?')->execute([SmsProspectReconciliation::json($proof)]);
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($db));
        self::assertSame([101, 102], array_column(SmsLegacyHistoryFiles::page($db, 0, 2), 'Event_ID'));
    }

    public function test_nonabsolute_missing_wrapper_and_directory_paths_refuse(): void
    {
        foreach (['part.csv', 'C:relative.csv', 'php://memory', 'data:text/plain,invalid', $this->directory,
            $this->directory.'/missing.csv', $this->directory."/bad\0.csv"] as $path) {
            $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], self::DATE), 'build');
        }
        $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [], self::DATE), 'build');
    }

    public function test_file_count_is_bounded_at_ten_and_ids_match_sql_int_bounds(): void
    {
        $path = $this->file(self::HEADER."\n".$this->record());
        $proof = SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), array_fill(0, 10, $path), self::DATE);
        self::assertCount(10, $proof['files']);
        self::assertSame(10, $proof['phone_occurrences']); self::assertSame(1, $proof['unique_events']);
        $db = new PDO('sqlite::memory:');
        $this->refused(fn () => SmsLegacyHistoryFiles::build($db, array_fill(0, 11, $path), self::DATE), 'build');
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn());
        [$db, , $proof] = $this->fixture();
        $db->exec('UPDATE events SET id=2147483647 WHERE id=7');
        $proof['event_max_id'] = 2147483647;
        $lines = '';
        foreach ($db->query('SELECT id,phone,send_date FROM events ORDER BY id')->fetchAll(PDO::FETCH_NUM) as $event) {
            $lines .= json_encode($event, JSON_THROW_ON_ERROR)."\n";
        }
        $proof['events_sha256'] = hash('sha256', $lines);
        $db->prepare('UPDATE state SET payload=?')->execute([SmsProspectReconciliation::json($proof)]);
        self::assertSame($proof, SmsLegacyHistoryFiles::proof($db));
        self::assertSame(2147483647, SmsLegacyHistoryFiles::page($db, 2147483646, 1)[0]['Event_ID']);
        $db->exec('UPDATE events SET id=2147483648 WHERE id=2147483647');
        $this->refused(fn () => SmsLegacyHistoryFiles::proof($db), 'proof');
        $this->refused(fn () => SmsLegacyHistoryFiles::page($db, 2147483646, 1), 'page');
    }

    public function test_hardlinks_and_symlinks_refuse(): void
    {
        $path = $this->file(self::HEADER."\n".$this->record());
        $hard = $this->directory.'/hard.csv'; $this->files[] = $hard;
        if (! @link($path, $hard)) self::markTestSkipped('Hardlinks are unavailable on this filesystem.');
        $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], self::DATE), 'build');
        $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$hard], self::DATE), 'build');
        self::assertTrue(unlink($hard));
        $symlink = $this->directory.'/symlink.csv'; $this->files[] = $symlink;
        if (! @symlink($path, $symlink)) self::markTestSkipped('Symlink privileges are unavailable.');
        $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$symlink], self::DATE), 'build');
    }

    public function test_external_exclusive_lock_is_refused_and_build_releases_its_locks(): void
    {
        $path = $this->file(self::HEADER."\n".$this->record()); $handle = fopen($path, 'rb');
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try { $this->refused(fn () => SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], self::DATE), 'build'); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
        SmsLegacyHistoryFiles::build(new PDO('sqlite::memory:'), [$path], self::DATE);
        $handle = fopen($path, 'rb');
        try { self::assertTrue(flock($handle, LOCK_EX | LOCK_NB)); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function test_mid_build_path_replacement_is_detected(): void
    {
        $raw = self::HEADER."\n".str_repeat($this->record(), 5001); $path = $this->file($raw);
        $backup = $this->directory.'/original.csv'; $this->files[] = $backup;
        $db = new SmsHistoryTestSqlite(); $replaced = false;
        $db->afterChunk = static function () use ($path, $backup, $raw, &$replaced): void {
            if (@rename($path, $backup)) { file_put_contents($path, $raw); $replaced = true; }
        };
        try { SmsLegacyHistoryFiles::build($db, [$path], self::DATE); self::assertFalse($replaced); }
        catch (RuntimeException $error) {
            self::assertTrue($replaced); self::assertNull($error->getPrevious());
            self::assertSame(0, $db->query('SELECT complete FROM state')->fetchColumn());
        }
        if (! $replaced) self::markTestSkipped('Filesystem does not permit replacing an open shared-locked file.');
    }
}

/** Local SQLite only; records durable chunk sizes and permits a deterministic source race. */
final class SmsHistoryTestSqlite extends PDO
{
    public array $committedEventCounts = [];
    public ?Closure $afterChunk = null;

    public function __construct() { parent::__construct('sqlite::memory:'); }

    public function commit(): bool
    {
        $count = $this->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $result = parent::commit(); $this->committedEventCounts[] = $count;
        if (count($this->committedEventCounts) === 2 && $this->afterChunk !== null) ($this->afterChunk)();
        return $result;
    }
}
