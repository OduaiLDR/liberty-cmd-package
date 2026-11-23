<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ReadNsfWorkbook;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class NsfHistoricalWorkbookStatementTest extends TestCase
{
    private const PERIOD = '2098-02-01';
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'nsf-saved-');
    }

    protected function tearDown(): void { unlink($this->path); }

    public function test_files_written_by_the_two_historical_formatters_remain_readable(): void
    {
        // Both originals were generated with synthetic rows, including a differently spelled, unpaid assignment.
        $workbooks = [
            'cfa7dc0' => '839bcc4bdfb69273c788f986480e2a4853efecfd6a469fb73539050c877798b2',
            '1052b08' => '75af42984f1fa020f7d3f73dbdcbd093d22a49fad7257ec85c157e020e96976a',
        ];
        foreach ($workbooks as $revision => $sha256) {
            $path = dirname(__DIR__) . '/Fixtures/nsf/' . $revision . '.xlsx';
            $statement = CustomerServiceCalculationSnapshot::forPinnedNsfWorkbook($path, $sha256, '2098-01-01', 'ldr', 'historical earner');
            self::assertSame('verified', $statement['status']);
            self::assertSame(225, $statement['amountCents']);
            self::assertSame([['clientId' => '9001', 'rateCents' => 225, 'amountCents' => 225, 'paymentDate' => '2098-01-05']], $statement['lines']);
        }
    }

    public static function layouts(): array
    {
        return [['Commission', 'Data', 'NGO'], ['Agent Summary', 'NSF Data', 'NGO'], ['Agent Summary', 'NSF Data', 'NSF Agent']];
    }

    private function workbook(string $summary = 'Agent Summary', string $data = 'NSF Data', string $header = 'NSF Agent'): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle($data)->fromArray([
            ['ID', 'Agent', 'NSF Action', 'Cleared Date', 'Valid Commission'],
            ['301', 'Andrea Mendoze', 'Frozen', Date::PHPToExcel(new \DateTimeImmutable('2098-03-02 UTC')), 'TRUE'],
            ['302', 'Andrea Mendoze', null, null, 'false'],
            ['303', 'Other private earner', 'Frozen', '2098-03-03', false],
        ], null, 'A1', true);
        $book->createSheet()->setTitle($summary)->fromArray([
            [$header, 'Assignments', 'Actions', 'Ratio', 'Actions Tier', 'Cleared Tier', 'Rate', 'Clears', 'Commission'],
            ['Andrea Mendoze', 2, 1, 0.5, 2, 1, 2.25, 1, 2.25],
            ['Zero Earner', 0, 0, 0, null, null, 0, 0, 0],
            ['Unassigned Agents'],
            ['Off Roster', null, null, null, null, null, null, null, 3.5],
        ], null, 'A1', true);
        return $book;
    }

    private function save(Spreadsheet $book): string
    {
        try { (new Xlsx($book))->setPreCalculateFormulas(false)->save($this->path); }
        finally { $book->disconnectWorksheets(); }
        return hash_file('sha256', $this->path);
    }

    private function read(string $hash, string $agent = ' Andrea  MENDOZA '): array
    {
        return CustomerServiceCalculationSnapshot::forPinnedNsfWorkbook($this->path, $hash, self::PERIOD, 'ldr', $agent);
    }

    #[DataProvider('layouts')]
    public function test_reads_actual_historical_layouts_without_archive_sidecar(string $summary, string $data, string $header): void
    {
        $sha = $this->save($this->workbook($summary, $data, $header));
        self::assertSame([
            'kind' => 'nsf_employee_statement_v1', 'period' => self::PERIOD, 'source' => 'ldr',
            'agentKey' => 'andrea mendoza', 'workbookSha256' => $sha, 'status' => 'verified', 'amountCents' => 225,
            'lines' => [['clientId' => '301', 'rateCents' => 225, 'amountCents' => 225, 'paymentDate' => '2098-03-02']],
        ], $this->read($sha));
        foreach ([['zero earner', 'verified', 0], ['unknown', 'missing', null], ['off roster', 'total_only', 350]] as [$agent, $status, $amount]) {
            $saved = $this->read($sha, $agent);
            self::assertSame($status, $saved['status']); self::assertSame($amount, $saved['amountCents']); self::assertSame([], $saved['lines']);
        }
        self::assertSame($sha, hash_file('sha256', $this->path));
    }

    public static function incompleteEvidence(): array
    {
        return [['rate'], ['reconstructed'], ['formula rate'], ['flags'], ['blank flag'], ['formula flag'],
            ['blank account'], ['formula account'], ['formula date'], ['summary only']];
    }

    #[DataProvider('incompleteEvidence')]
    public function test_preserves_recorded_total_when_original_pricing_cannot_be_verified(string $case): void
    {
        $book = $this->workbook();
        $summary = $book->getSheetByName('Agent Summary');
        $data = $book->getSheetByName('NSF Data');
        match ($case) {
            'rate' => $summary->setCellValue('G2', null),
            'reconstructed' => $summary->getComment('G2')->getText()->createTextRun('Rate reconciled from the original frozen commission amount.'),
            'formula rate' => $summary->setCellValue('G2', '=I2/H2'),
            'flags' => $data->setCellValue('E1', null),
            'blank flag' => $data->setCellValue('E2', null),
            'formula flag' => $data->setCellValue('E2', '=1'),
            'blank account' => $data->setCellValue('A2', null),
            'formula account' => $data->setCellValue('A2', '=300+1'),
            'formula date' => $data->setCellValue('D2', '=TODAY()'),
            'summary only' => $book->removeSheetByIndex(0),
        };
        $result = $this->read($this->save($book));
        self::assertSame('total_only', $result['status']); self::assertSame(225, $result['amountCents']); self::assertSame([], $result['lines']);
    }

    public static function zeroEvidence(): array
    {
        return [
            ['positive rate and clears', false], ['positive rate no counts', false], ['clears zero known eligible', false],
            ['missing flag before known eligible', false], ['all ineligible contradict clears', false], ['summary only positive rate and clears', false],
            ['summary only no pricing', true], ['zero rate positive clears', true], ['unknown eligibility and rate', true],
        ];
    }

    #[DataProvider('zeroEvidence')]
    public function test_explicit_zero_is_checked_against_each_available_original_fact(string $case, bool $valid): void
    {
        $book = $this->workbook();
        $summary = $book->getSheetByName('Agent Summary');
        $data = $book->getSheetByName('NSF Data');
        $summary->setCellValue('I2', 0);
        switch ($case) {
            case 'positive rate no counts': $summary->setCellValue('H2', null); break;
            case 'clears zero known eligible': $summary->setCellValue('H2', 0); break;
            case 'missing flag before known eligible':
                $data->setCellValue('E2', null)->setCellValue('E3', true);
                $summary->setCellValue('G2', null)->setCellValue('H2', 0);
                break;
            case 'all ineligible contradict clears': $data->setCellValue('E2', false); $summary->setCellValue('G2', null); break;
            case 'summary only positive rate and clears': $book->removeSheetByIndex(0); break;
            case 'summary only no pricing': $book->removeSheetByIndex(0); $summary->setCellValue('G2', null); break;
            case 'zero rate positive clears': $summary->setCellValue('G2', 0); break;
            case 'unknown eligibility and rate': $summary->setCellValue('G2', null); $data->setCellValue('E2', null); break;
        }
        $sha = $this->save($book);
        if (!$valid) $this->expectException(\RuntimeException::class);
        $saved = $this->read($sha);
        self::assertSame(['verified', 0, []], [$saved['status'], $saved['amountCents'], $saved['lines']]);
    }

    public static function partialSummary(): array
    {
        return [
            ['H2', null, 'total_only'], ['H2', '=1', 'total_only'],
            ['B2', null, 'verified'], ['B2', '=2', 'verified'], ['C2', null, 'verified'], ['C2', '=1', 'verified'],
            ['D2', null, 'verified'], ['D2', '=0.5', 'verified'], ['no action header', null, 'verified'],
        ];
    }

    #[DataProvider('partialSummary')]
    public function test_missing_numeric_proof_preserves_positive_recorded_amount(string $cell, mixed $value, string $status): void
    {
        $book = $this->workbook();
        if ($cell === 'no action header') $book->getSheetByName('NSF Data')->setCellValue('C1', null);
        else $book->getSheetByName('Agent Summary')->setCellValue($cell, $value);
        $saved = $this->read($this->save($book));
        self::assertSame($status, $saved['status']); self::assertSame(225, $saved['amountCents']);
    }

    public function test_literal_return_dates_bind_a_metadata_free_workbook_even_for_a_missing_employee(): void
    {
        $book = $this->workbook('Commission', 'Data', 'NGO');
        $book->getSheetByName('Data')->setCellValue('F1', 'NSF Returned Date')->setCellValue('F4', '2098-01-30');
        $sha = $this->save($book);
        $this->expectException(\RuntimeException::class);
        $this->read($sha, 'No Such Agent');
    }

    public static function historicalIdentity(): array
    {
        return [
            ['NGO', false, 'exact', 'verified', ['301']],
            ['NSF Agent', false, 'exact', 'verified', ['301']],
            ['NSF Agent', false, 'normalized', 'verified', ['301', '304']],
            ['NSF Agent', true, 'normalized', 'verified', ['301', '304']],
            ['NSF Agent', false, 'ambiguous', 'total_only', []],
        ];
    }

    public function test_unselected_case_variant_with_a_repeated_id_does_not_veto_proven_exact_spelling(): void
    {
        $book = $this->workbook();
        $book->getSheetByName('NSF Data')->fromArray(['301', 'ANDREA MENDOZE', 'Paid', '2098-03-03', true], null, 'A5', true);
        $saved = $this->read($this->save($book));
        self::assertSame('verified', $saved['status']);
        self::assertSame(['301'], array_column($saved['lines'], 'clientId'));
    }

    public function test_old_raw_group_with_repeated_account_only_supports_the_original_total(): void
    {
        $book = $this->workbook('Commission', 'Data', 'NGO');
        $book->getSheetByName('Data')->setCellValue('A3', '301');
        $saved = $this->read($this->save($book));
        self::assertSame(['total_only', 225, []], [$saved['status'], $saved['amountCents'], $saved['lines']]);
    }

    #[DataProvider('historicalIdentity')]
    public function test_uses_the_provable_historical_grouping_for_case_variant_detail(string $header, bool $marked, string $group, string $status, array $ids): void
    {
        $book = $this->workbook('Agent Summary', 'NSF Data', $header);
        $book->getSheetByName('NSF Data')->fromArray(['304', ' ANDREA  MENDOZE ', 'Action', '2098-03-03', 'true'], null, 'A5', true);
        $summary = $book->getSheetByName('Agent Summary');
        if ($group === 'normalized') {
            $summary->fromArray([3, 2, 0.6667], null, 'B2', true)->setCellValue('H2', 2)->setCellValue('I2', 4.5);
        } elseif ($group === 'ambiguous') {
            $summary->setCellValue('B2', null)->setCellValue('C2', null)->setCellValue('D2', null);
            $book->getSheetByName('NSF Data')->setCellValue('E5', null);
        }
        if ($marked) $book->createSheet()->setTitle('Run Status')->fromArray([['Source', 'LDR'], ['Period Start', self::PERIOD]]);
        $saved = $this->read($this->save($book));
        self::assertSame($status, $saved['status']); self::assertSame($ids, array_column($saved['lines'], 'clientId'));
    }

    public static function invalidEvidence(): array
    {
        return [['digest'], ['alias collision'], ['formula total'], ['fractional cent'], ['summary mismatch'], ['duplicate contact'],
            ['mixed versions'], ['wrong historical month'], ['wrong historical company'], ['wrong modern cutoff'],
            ['wrong old cutoff'], ['wrong old final after'], ['positive mismatch no detail'], ['positive mismatch partial flags'],
            ['summary ratio without action field'], ['summary actions exceed assignments'], ['summary clears exceed assignments'],
            ['positive counts with no matching detail']];
    }

    #[DataProvider('invalidEvidence')]
    public function test_rejects_ambiguous_contradictory_or_damaged_evidence(string $case): void
    {
        $book = $this->workbook();
        $summary = $book->getSheetByName('Agent Summary');
        match ($case) {
            'digest' => null,
            'alias collision' => $summary->setCellValue('A3', 'Andrea Mendoza'),
            'formula total' => $summary->setCellValue('I2', '=G2*H2'),
            'fractional cent' => $summary->setCellValue('I2', 2.251),
            'summary mismatch' => $summary->setCellValue('I2', 2.5),
            'summary ratio without action field' => [$book->getSheetByName('NSF Data')->setCellValue('C1', null), $summary->setCellValue('D2', 1)],
            'summary actions exceed assignments' => [$book->removeSheetByIndex(0), $summary->setCellValue('C2', 3)],
            'summary clears exceed assignments' => [$book->removeSheetByIndex(0), $summary->setCellValue('B2', 0)->setCellValue('C2', 0)],
            'duplicate contact' => $book->getSheetByName('NSF Data')->setCellValue('A3', '301'),
            'mixed versions' => $book->createSheet()->setTitle('Commission'),
            'wrong historical month' => $book->createSheet()->setTitle('Run Status')->fromArray(['Period Start', '2098-03-01']),
            'wrong historical company' => $book->createSheet()->setTitle('Run Status')->fromArray(['Source', 'Progress Law']),
            'wrong modern cutoff' => $book->getProperties()->setCustomProperty('NSF Final Runs After (UTC)', '2098-04-06 07:00:00.000000'),
            'wrong old cutoff' => $book->createSheet()->setTitle('Run Status')->fromArray(['Payment cutoff (Pacific)', '2098-04-05 23:59:59 PDT']),
            'wrong old final after' => $book->createSheet()->setTitle('Run Status')->fromArray(['Final runs may start after (UTC)', '2098-04-06 07:00:00.000000']),
            'positive mismatch no detail' => [$summary->setCellValue('I2', 2.5), $book->removeSheetByIndex(0)],
            'positive mismatch partial flags' => [$summary->setCellValue('I2', 2.5), $book->getSheetByName('NSF Data')->setCellValue('E2', null)],
            'positive counts with no matching detail' => $book->getSheetByName('NSF Data')->setCellValue('B2', 'Other private earner')
                ->setCellValue('B3', 'Other private earner'),
        };
        $sha = $this->save($book);
        $this->expectException(\RuntimeException::class);
        $this->read($case === 'digest' ? str_repeat('a', 64) : $sha);
    }

    public function test_cli_emits_private_statement_only_on_direct_console_invocation(): void
    {
        $sha = $this->save($this->workbook('Commission', 'Data', 'NGO'));
        $container = new class extends Container { public function runningUnitTests(): bool { return true; } };
        $artisan = new Artisan($container, new Dispatcher($container), 'test');
        $artisan->add(new ReadNsfWorkbook);
        $argv = ['artisan', 'payroll:read-nsf-workbook', self::PERIOD, 'ldr', 'andrea mendoza', $this->path, $sha];
        $console = new class extends ConsoleOutput {
            public string $captured = '';
            protected function doWrite(string $message, bool $newline): void { $this->captured .= $message . ($newline ? PHP_EOL : ''); }
        };
        self::assertSame(0, $artisan->run(new ArgvInput($argv), $console));
        self::assertSame($this->read($sha), json_decode($console->captured, true, flags: JSON_THROW_ON_ERROR));
        $log = new BufferedOutput;
        self::assertSame(1, $artisan->run(new ArgvInput($argv), $log)); self::assertSame('', $log->fetch());
        [$command, $args] = [$argv[1], array_combine(['period', 'source', 'agent', 'workbook', 'sha256'], array_slice($argv, 2))];
        self::assertSame(1, $artisan->call($command, $args)); self::assertSame('', $artisan->output());
    }
}
