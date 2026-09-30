<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\GenerateLendingTowerInvoices;
use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\InvoiceBackupWorkbook;
use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\ProgressLawLeadQuery;
use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\UsStateClassification;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GenerateLendingTowerInvoicesTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        return (new ReflectionMethod(GenerateLendingTowerInvoices::class, $method))
            ->invoke(new GenerateLendingTowerInvoices(), ...$args);
    }

    public function test_first_qualifying_event_wins_across_months_and_status_ids(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Execute the production SQL with SQLite equivalents for Snowflake scalar functions.
        $db->sqliteCreateFunction('CONVERT_TIMEZONE', static fn ($tz, $stamp) =>
            (new \DateTimeImmutable($stamp))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:s'), 2);
        $db->sqliteCreateFunction('TO_CHAR', static fn ($value, $format) => $value, 2);
        $db->sqliteCreateFunction('CONCAT', static fn (...$parts) => implode('', $parts));
        $db->exec('CREATE TABLE CONTACTS (ID INTEGER, FIRSTNAME TEXT, LASTNAME TEXT, STATE TEXT, DEL INTEGER, ISCOAPP INTEGER, LEADSTATUS INTEGER)');
        $db->exec('CREATE TABLE CONTACTS_STATUS (ID INTEGER, CONTACT_ID INTEGER, STATUS_ID INTEGER, STAMP TEXT)');
        $db->exec('CREATE TABLE CONTACTS_LEAD_STATUS (ID INTEGER, TITLE TEXT)');
        $status = $db->prepare('INSERT INTO CONTACTS_LEAD_STATUS VALUES (?, ?)');
        $statusIds = [1 => 293539, 2 => 293285, 3 => 293531, 4 => 293533, 5 => 293527];
        foreach ([1 => 'Contract Sent', 2 => 'Submitted', 3 => 'Rejected (Pitched DS)',
            4 => 'Rejected (Not Interested DS)', 5 => 'Rejected (Partial DS)', 6 => 'Submitted',
            7 => 'ProLaw Enrolled', 8 => 'New Lead', 9 => 'Dropped', 10 => 'NSF', 11 => 'Duplicate Lead'] as $id => $title) {
            $status->execute([$statusIds[$id] ?? $id, $title]);
        }
        $contact = $db->prepare('INSERT INTO CONTACTS VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (range(1, 14) as $id) {
            $contact->execute([$id, $id === 10 ? '' : 'Test', 'Client', $id === 5 ? 'CA' : 'CO',
                $id === 6 ? 1 : 0, $id === 7 ? 1 : 0, [1 => 7, 10 => 11, 13 => 9, 14 => 10][$id] ?? 8]);
        }
        $history = [
            [1, 1, 8, '2026-07-01 12:00:00+00:00'],
            [2, 1, 1, '2026-08-03 12:00:00+00:00'], [3, 1, 2, '2026-08-10 12:00:00+00:00'],
            [4, 2, 2, '2026-07-31 12:00:00+00:00'], [5, 2, 1, '2026-08-10 12:00:00+00:00'],
            [6, 3, 1, '2026-08-01 06:59:59+00:00'], // July in LA: excluded.
            [7, 4, 1, '2026-08-01 07:00:00+00:00'], // Exact August lower bound.
            [8, 5, 1, '2026-08-03 12:00:00+00:00'],
            [9, 6, 1, '2026-08-03 12:00:00+00:00'], [10, 7, 1, '2026-08-03 12:00:00+00:00'],
            [11, 8, 8, '2026-08-03 12:00:00+00:00'], // Nonqualifying only.
            [13, 9, 2, '2026-08-03 12:00:00+00:00'], [12, 9, 1, '2026-08-03 12:00:00+00:00'], // Tied stamps.
            [14, 10, 5, '2026-08-03 12:00:00+00:00'], // Blank name/current duplicate still follows requested rule.
            [15, 11, 2, '2026-09-01 06:59:59+00:00'], // Last August second in LA.
            [16, 12, 1, '2026-09-01 07:00:00+00:00'], // Exclusive upper bound.
            [17, 13, 3, '2026-08-03 12:00:00+00:00'],
            [18, 14, 4, '2026-08-03 12:00:00+00:00'],
            [19, 8, 6, '2026-08-03 12:00:00+00:00'], // Same title, unapproved ID: must not qualify.
        ];
        $insert = $db->prepare('INSERT INTO CONTACTS_STATUS VALUES (?, ?, ?, ?)');
        foreach ($history as $row) {
            $row[2] = $statusIds[$row[2]] ?? $row[2];
            $insert->execute($row);
        }
        $sql = str_replace('::TIMESTAMP_NTZ', '', ProgressLawLeadQuery::sql('2026-08-01', '2026-09-01', ['CO']));
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_column($rows, null, 'CONTACT_ID');
        $ids = array_keys($byId);
        sort($ids);
        self::assertSame([1, 4, 9, 10, 11, 13, 14], $ids);
        self::assertCount(7, $rows);
        self::assertSame('Contract Sent', $byId[9]['BILLABLE_STATUS']);
        self::assertSame('ProLaw Enrolled', $byId[1]['CURRENT_STATUS']);
        self::assertSame('Dropped', $byId[13]['CURRENT_STATUS']);
        self::assertSame('NSF', $byId[14]['CURRENT_STATUS']);
        self::assertSame('2026-08-01 00:00:00', $byId[4]['STATUS_LOCAL']);
        self::assertSame(192500, $this->invoke('summarizeProgressLaw', [$rows])['total_cents']);
        $september = $db->query(str_replace('::TIMESTAMP_NTZ', '', ProgressLawLeadQuery::sql('2026-09-01', '2026-10-01', ['CO'])))->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([12], array_column($september, 'CONTACT_ID'));
    }

    public function test_duplicate_contact_fails_instead_of_double_billing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invoke('summarizeProgressLaw', [[['CONTACT_ID' => '123'], ['CONTACT_ID' => '123']]]);
    }

    public function test_status_ids_use_lending_tower_source_and_previous_month_default(): void
    {
        self::assertSame('lt', ProgressLawLeadQuery::SOURCE);
        self::assertSame([293539, 293533, 293527, 293531, 293285], array_keys(ProgressLawLeadQuery::STATUSES));
        $sql = ProgressLawLeadQuery::sql('2026-08-01', '2026-09-01', ['CO']);
        self::assertStringContainsString('s.STATUS_ID IN (293539, 293533, 293527, 293531, 293285)', $sql);
        self::assertStringNotContainsString('cls.TITLE IN', $sql);
        $previous = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Los_Angeles')))->modify('-1 month');
        self::assertSame([$previous->format('Y-m-01'), $previous->modify('first day of next month')->format('Y-m-01'), $previous->format('F Y')], $this->invoke('resolvePeriod', ['']));
    }

    public function test_state_migration_seeds_all_states_and_reads_updated_classification(): void
    {
        $originalContainer = Container::getInstance();
        $originalFacade = Facade::getFacadeApplication();
        $container = new Container();
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
        try {
            $migration = require dirname(__DIR__, 2) . '/database/lending-tower-migrations/2026_09_29_000001_create_us_state_classifications_table.php';
            $migration->up();
            $db = $capsule->getConnection();
            self::assertSame(50, $db->table(UsStateClassification::TABLE)->count());
            self::assertSame(24, $db->table(UsStateClassification::TABLE)->whereNull('classification')->count());
            self::assertSame(['CO', 'CT', 'DE', 'GA', 'HI', 'IA', 'ID', 'IL', 'KS', 'LA', 'ME', 'MI', 'MN', 'MT', 'ND', 'NE', 'NH', 'NJ', 'NV', 'OH', 'SC', 'VA', 'VT', 'WA', 'WI', 'WY'], UsStateClassification::progressLawStates());
            $db->table(UsStateClassification::TABLE)->where('state_code', 'CO')->update(['classification' => 'Excluded']);
            self::assertNotContains('CO', UsStateClassification::progressLawStates());
            $db->table(UsStateClassification::TABLE)->update(['classification' => 'Open']);
            try {
                UsStateClassification::progressLawStates();
                self::fail('An empty PLAW classification must not generate a silent zero invoice.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('No PLAW states', $e->getMessage());
            }
            $migration->down();
            self::assertFalse($db->getSchemaBuilder()->hasTable(UsStateClassification::TABLE));
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($originalFacade);
            Container::setInstance($originalContainer);
        }
    }

    public function test_backup_roundtrip_preserves_statuses_ids_and_presentation(): void
    {
        $bytes = $this->invoke('progressLawBackup', [[
            'lead_count' => 1, 'total_cents' => 27500,
            'leads' => [['CONTACT_ID' => '001234567890123456', 'CLIENT' => '=Literal Client', 'STATE' => 'CO',
                'STATUS_LOCAL' => '2026-08-03 05:00:00', 'BILLABLE_STATUS' => 'Contract Sent', 'CURRENT_STATUS' => 'ProLaw Enrolled']],
        ], ['month' => 'August 2026'], 'LT-PL-2026-08']);
        $path = tempnam(sys_get_temp_dir(), 'lt-test-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            self::assertSame('001234567890123456', $sheet->getCell('A5')->getValue());
            self::assertSame('=Literal Client', $sheet->getCell('B5')->getValue());
            self::assertSame('s', $sheet->getCell('B5')->getDataType());
            self::assertSame('Qualifying Status Date', $sheet->getCell('D4')->getValue());
            self::assertSame('2026-08-03', $sheet->getCell('D5')->getValue());
            self::assertSame('Contract Sent', $sheet->getCell('E5')->getValue());
            self::assertSame('ProLaw Enrolled', $sheet->getCell('F5')->getValue());
            $this->assertHiddenGridlines($path, 1);
            self::assertSame(19.0, $sheet->getColumnDimension('A')->getWidth());
            self::assertFalse($sheet->getColumnDimension('A')->getAutoSize());
            self::assertSame('A4:F5', $sheet->getAutoFilter()->getRange());
            self::assertSame('A5', $sheet->getFreezePane());
            self::assertNotSame('none', $sheet->getStyle('B5')->getBorders()->getBottom()->getBorderStyle());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_ldr_workbook_keeps_both_sheets_and_reconciling_totals(): void
    {
        $invoice = $this->invoke('summarizeLdr', [[
            ['LLG_ID' => '0001', 'Client' => 'Test Client', 'First_Payment_Cleared_Date' => '2026-08-03', 'Debt_Source' => 'Sold_Debt', 'Debt_Basis' => '1000.50'],
            ['LLG_ID' => '0002', 'Client' => 'Second Client', 'First_Payment_Cleared_Date' => '2026-08-04', 'Debt_Source' => 'Debt_Amount', 'Debt_Basis' => '2000.00'],
        ], [
            ['LLG_ID' => '0001', 'Client' => 'Test Client', 'First_Payment_Cleared_Date' => '2026-08-03', 'Lookback_Date' => null, 'Cancel_Date' => '2026-08-10', 'Debt_Basis' => '1000.50'],
        ], '2026-08-01', '2026-09-01']);
        $bytes = $this->invoke('ldrBackup', [$invoice, ['month' => 'August 2026'], 'LT-LDR-2026-08']);
        $path = tempnam(sys_get_temp_dir(), 'lt-test-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            self::assertSame(['Enrollments', 'Lookback Deduction'], $book->getSheetNames());
            self::assertEquals(3000.50, $book->getSheet(0)->getCell('E7')->getCalculatedValue());
            self::assertEquals(1000.50, $book->getSheet(1)->getCell('F6')->getCalculatedValue());
            foreach ($book->getAllSheets() as $index => $sheet) {
                $this->assertHiddenGridlines($path, $index + 1);
                self::assertSame(19.0, $sheet->getColumnDimension('A')->getWidth());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_empty_backup_remains_a_valid_workbook(): void
    {
        $book = new InvoiceBackupWorkbook();
        $book->addSheet('Leads', 'No billable leads', '0 leads', ['Contact ID', 'Client'], []);
        self::assertStringStartsWith('PK', $book->toBytes());
    }

    private function assertHiddenGridlines(string $path, int $sheetNumber): void
    {
        // PhpSpreadsheet 1.x's reader treats printOptions.gridLinesSet=true as screen gridlines.
        // Check the saved Excel settings directly instead of that unrelated reader behavior.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName("xl/worksheets/sheet{$sheetNumber}.xml"));
        self::assertSame('false', (string) $xml->sheetViews->sheetView['showGridLines']);
        self::assertSame('false', (string) $xml->printOptions['gridLines']);
        $zip->close();
    }
}
