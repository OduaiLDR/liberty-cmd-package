<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateOfferAuthorizationReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateOfferAuthorizationReport\GenerateOfferAuthorizationReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;

class GenerateOfferAuthorizationReportTest extends TestCase
{
    public function test_report_date_is_yesterday(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');

        $this->assertSame('2026-07-21', Carbon::today()->toDateString());
        $this->assertSame('2026-07-20', Carbon::today()->subDay()->toDateString());
    }

    public function test_query_uses_offer_status_and_maps_rows(): void
    {
        $connector = new OfferAuthorizationFakeConnector([
            'data' => [[
                'OFFER_ID' => '9001',
                'LLG_ID' => 'LLG-123',
                'TITLE' => 'LDR Premium Plan',
                'FIRSTNAME' => 'Ada',
                'LASTNAME' => 'Lovelace',
                'ADDRESS' => '1 Main St',
                'ADDRESS2' => '',
                'ADDRESS3' => '',
                'CITY' => 'Orange',
                'STATE' => 'CA_South',
                'ZIP' => '92868',
                'RETURN_ADDRESS' => null,
            ]],
        ]);

        $rows = $this->invokePrivate(
            new GenerateOfferAuthorizationReport,
            'fetchOfferCandidates',
            [$connector, 2088]
        );

        $this->assertStringContainsString('WHERE s.OFFER_STATUS = 2088', $connector->sql);
        $this->assertStringContainsString('FROM SETTLEMENT_OFFERS AS s', $connector->sql);
        $this->assertSame([[
            'offer_id' => '9001',
            'llg_id' => 'LLG-123',
            'title' => 'LDR Premium Plan',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'address' => '1 Main St',
            'address2' => '',
            'address3' => '',
            'city' => 'Orange',
            'state' => 'CA South',
            'zip' => '92868',
            'return_address' => '',
        ]], $rows);
    }

    public function test_portal_normalization_matches_vba(): void
    {
        $command = new GenerateOfferAuthorizationReport;

        $ldr = $this->invokePrivate($command, 'normalizePortalFields', [[
            'offer_id' => '1',
            'llg_id' => 'LLG-1',
            'title' => 'LT LDR Special',
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => '',
            'address2' => '',
            'address3' => '',
            'city' => '',
            'state' => 'CA',
            'zip' => '',
            'return_address' => '',
        ], 'LDR']);

        $this->assertSame('LDR', $ldr['title']);
        $this->assertSame('333 City Blvd W 17 FL, Orange CA, 92868', $ldr['return_address']);

        $plawOnLdr = $this->invokePrivate($command, 'normalizePortalFields', [[
            'offer_id' => '2',
            'llg_id' => 'LLG-2',
            'title' => 'PLAW Basic',
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => '',
            'address2' => '',
            'address3' => '',
            'city' => '',
            'state' => 'CA',
            'zip' => '',
            'return_address' => '',
        ], 'LDR']);

        $this->assertSame('PLAW', $plawOnLdr['title']);
        $this->assertSame('350 10th Avenue, Suite 1000, San Diego CA, 92101-7496', $plawOnLdr['return_address']);

        $plaw = $this->invokePrivate($command, 'normalizePortalFields', [[
            'offer_id' => '3',
            'llg_id' => 'LLG-3',
            'title' => 'Anything',
            'firstname' => 'A',
            'lastname' => 'B',
            'address' => '',
            'address2' => '',
            'address3' => '',
            'city' => '',
            'state' => 'WI',
            'zip' => '',
            'return_address' => '',
        ], 'PLAW']);

        $this->assertSame('Progress Law', $plaw['title']);
        $this->assertSame('7520 39th Ave, Suite 102, Kenosha, WI 53142', $plaw['return_address']);
    }

    public function test_tracking_skips_prior_authorizations_and_inserts_new_ones(): void
    {
        $sql = new OfferAuthorizationSqlFakeConnector([
            'select' => [
                ['success' => true, 'data' => [['Offer_ID' => '100', 'LLG_ID' => 'LLG-100']]],
            ],
            'insert' => [
                ['success' => true, 'data' => [], 'row_count' => 1],
            ],
        ]);

        $rows = $this->invokePrivate(new GenerateOfferAuthorizationReport, 'prepareAuthorizationRows', [[
            [
                'offer_id' => '100',
                'llg_id' => 'LLG-100',
                'title' => 'LDR Plan A',
                'firstname' => 'Zed',
                'lastname' => 'Young',
                'address' => '',
                'address2' => '',
                'address3' => '',
                'city' => '',
                'state' => 'CA',
                'zip' => '',
                'return_address' => '',
            ],
            [
                'offer_id' => '200',
                'llg_id' => 'LLG-200',
                'title' => 'LDR Plan B',
                'firstname' => 'Ann',
                'lastname' => 'Able',
                'address' => '',
                'address2' => '',
                'address3' => '',
                'city' => '',
                'state' => 'CA',
                'zip' => '',
                'return_address' => '',
            ],
        ], 'LDR', '2026-07-21', $sql, true]);

        $this->assertCount(1, $rows);
        $this->assertSame('200', $rows[0]['offer_id']);
        $this->assertSame('LDR', $rows[0]['title']);
        $this->assertCount(1, $sql->selectSql);
        $this->assertCount(0, $sql->insertSql);

        $this->invokePrivate(new GenerateOfferAuthorizationReport, 'recordAuthorizations', [$sql, $rows, '2026-07-21']);

        $this->assertCount(1, $sql->insertSql);
        $this->assertStringContainsString('INSERT INTO TblOfferAuthorization', $sql->insertSql[0]);
        $this->assertSame(['200', 'LLG-200', '2026-07-21'], $sql->insertParams[0]);
    }

    public function test_formatter_writes_title_headers_and_values(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->invokePrivate(new Formatter, 'fillSheet', [$sheet, [[
            'offer_id' => '9001',
            'llg_id' => 'LLG-123',
            'title' => 'LDR',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'address' => '1 Main St',
            'address2' => 'Unit 2',
            'address3' => '',
            'city' => 'Orange',
            'state' => 'CA',
            'zip' => '92868',
            'return_address' => '333 City Blvd W 17 FL, Orange CA, 92868',
        ]], '07/20/2026']);

        $this->assertSame('Offer Authorization Report - 07/20/2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(
            ['OFFER_ID', 'LLG_ID', 'TITLE', 'FIRSTNAME', 'LASTNAME', 'ADDRESS', 'ADDRESS2', 'ADDRESS3', 'CITY', 'STATE', 'ZIP', 'RETURN_ADDRESS'],
            $sheet->rangeToArray('A3:L3')[0]
        );
        $this->assertSame('9001', $sheet->getCell('A4')->getValue());
        $this->assertSame('LLG-123', $sheet->getCell('B4')->getValue());
        $this->assertSame('LDR', $sheet->getCell('C4')->getValue());
        $this->assertSame('Lovelace', $sheet->getCell('E4')->getValue());
        $this->assertSame('92868', $sheet->getCell('K4')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_invalid_connector_result_fails_instead_of_empty_report(): void
    {
        $connector = new OfferAuthorizationFakeConnector(['unexpected' => true]);

        $this->expectException(\UnexpectedValueException::class);
        $this->invokePrivate(
            new GenerateOfferAuthorizationReport,
            'fetchOfferCandidates',
            [$connector, 2088]
        );
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}

final class OfferAuthorizationFakeConnector extends DBConnector
{
    public string $sql = '';

    public function __construct(private readonly array $result) {}

    public function query(string $sql, array $bindings = []): array
    {
        $this->sql = $sql;

        return $this->result;
    }
}

final class OfferAuthorizationSqlFakeConnector extends DBConnector
{
    /** @var list<string> */
    public array $selectSql = [];

    /** @var list<string> */
    public array $insertSql = [];

    /** @var list<array<int, mixed>> */
    public array $insertParams = [];

    private int $selectIndex = 0;

    private int $insertIndex = 0;

    /**
     * @param  array{select:list<array<string,mixed>>, insert:list<array<string,mixed>>}  $results
     */
    public function __construct(private readonly array $results) {}

    public function querySqlServer(string $sql, array $params = []): array
    {
        if (stripos($sql, 'SELECT') === 0) {
            $this->selectSql[] = $sql;

            return $this->results['select'][$this->selectIndex++] ?? ['success' => false, 'error' => 'no select result'];
        }

        $this->insertSql[] = $sql;
        $this->insertParams[] = $params;

        return $this->results['insert'][$this->insertIndex++] ?? ['success' => false, 'error' => 'no insert result'];
    }
}
