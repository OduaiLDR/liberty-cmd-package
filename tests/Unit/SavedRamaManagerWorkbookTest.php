<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SavedRamaManagerWorkbook;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class SavedRamaManagerWorkbookTest extends TestCase
{
    private array $paths = [];
    protected function tearDown(): void { foreach ($this->paths as $path) if (is_file($path)) unlink($path); parent::tearDown(); }

    private function save(Spreadsheet $book): string
    { $path = tempnam(sys_get_temp_dir(), 'saved-rama-'); $this->paths[] = $path; (new Xlsx($book))->save($path); $book->disconnectWorksheets(); return $path; }

    private function fixture(): array
    {
        $headers = ['ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results', 'Enrolled Debt', 'Cleared Payments',
            'Reconsideration Date', 'Dropped Date', 'Retention Payment Date', 'T1 Rate', 'T2 Rate', 'T3 Rate', 'T4 Rate', 'Cancel Request Date'];
        $rows = [[101, 'Client One', 'Synthetic Agent', '2098-09-06', 'RETAINED', 10000, 2, '2098-09-01', null, '2098-09-10', 1, 2, 3, 4, '2098-09-01'],
            [102, 'Client Two', 'Synthetic Agent', '2098-09-06', 'DROPPED', 15000, 1, '2098-09-02', '2098-09-07', null, 1, 2, 3, 4, '2098-09-02']];
        $artifacts = [];
        foreach (['ldr', 'plaw'] as $index => $source) {
            $book = new Spreadsheet; $book->getActiveSheet()->setTitle('Retention Data')->fromArray([$headers, $rows[$index]], null, 'A1');
            $path = $this->save($book); $key = 'retention_' . $source;
            $artifacts[$key] = ['key' => $key, 'period' => '2098-09-01', 'path' => $path, 'sha256' => hash_file('sha256', $path), 'context' => []];
        }
        $book = new Spreadsheet; $book->getProperties()->setCreated(strtotime('2098-10-04T01:00:08Z'));
        $managerHeaders = $headers; $managerHeaders[7] = 'Reconsideration'; $managerHeaders[9] = 'Payment Date';
        foreach ([10, 11, 12, 13] as $index) $managerHeaders[$index] = 'Tier ' . ($index - 9) . ' Rate';
        $managerHeaders = [...$managerHeaders, 'Tranche', 'Cut Off', 'Made Cut Off', 'Bonus'];
        $managerRows = array_map(static fn ($row, $i) => [...$row, 50, '2098-09-30', 'x', $i === 0 ? 'Bonus' : 'No Bonus'], $rows, [0, 1]);
        // Display rates are deliberately absent in manager output, as in real nonretained rows.
        foreach ($managerRows as &$row) foreach ([10, 11, 12, 13] as $index) $row[$index] = null; unset($row);
        $book->getActiveSheet()->setTitle('Retention Data')->fromArray([$managerHeaders, ...$managerRows], null, 'A1');
        $book->createSheet()->setTitle('Commission Summary')->fromArray([
            ['Customer Support Manager Commission'], [], ['Retention'], ['Reconsideration Pending', 2], ['Retained', 1], ['Rate', 0.5], ['Commission 1', 1000], [],
            ['Bonus (NGF)'], ['Bonus Reconsideration', 2], ['Bonus Retained', 1], ['Rate', 0.5], ['Commission 2', 750], [], ['Commission Total', 1750]], null, 'A1');
        return [$this->save($book), $artifacts];
    }

    public function test_saved_context_replays_existing_calculation_and_keeps_real_capture_date(): void
    {
        [$path, $artifacts] = $this->fixture();
        $before = hash_file('sha256', $path);
        $result = SavedRamaManagerWorkbook::read($path, $before, '2098-09-01', $artifacts);
        self::assertSame(1000, $result['snapshot']['summary']['commission']); self::assertSame(750, $result['snapshot']['summary']['bonusCommission']);
        self::assertSame(1750.0, $result['snapshot']['summary']['totalPay']);
        self::assertSame('2098-10-04T01:00:08Z', $result['managerWorkbookEvidence']['capturedAt']);
        self::assertSame(2, $result['managerWorkbookEvidence']['clientCount']); self::assertFalse($result['managerWorkbookEvidence']['paymentEligible']);
        self::assertSame($before, hash_file('sha256', $path)); self::assertArrayNotHasKey('trancheByClient', $result);
    }

    public function test_hash_mismatch_rejects_before_any_calculation(): void
    {
        [$path, $artifacts] = $this->fixture(); $called = false;
        try { SavedRamaManagerWorkbook::read($path, str_repeat('a', 64), '2098-09-01', $artifacts, function () use (&$called) { $called = true; }); self::fail('Hash must reject'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('identity', $error->getMessage()); }
        self::assertFalse($called);
    }

    public function test_ambiguous_context_changed_source_and_summary_cannot_be_accepted(): void
    {
        foreach (['R2' => 'yes', 'S2' => 'Maybe', 'P2' => 1.5, 'B2' => 'Changed Client', 'A3' => 101, 'Q2' => '2098-02-30'] as $cell => $value) {
            [$path, $artifacts] = $this->fixture(); $book = IOFactory::load($path); $book->getSheetByName('Retention Data')->setCellValue($cell, $value); (new Xlsx($book))->save($path); $book->disconnectWorksheets();
            try { SavedRamaManagerWorkbook::read($path, hash_file('sha256', $path), '2098-09-01', $artifacts); self::fail('Malformed input must reject: ' . $cell); }
            catch (\RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        [$path, $artifacts] = $this->fixture(); $book = IOFactory::load($path); $book->getSheetByName('Commission Summary')->setCellValue('B7', 750)->setCellValue('B13', 1000); (new Xlsx($book))->save($path); $book->disconnectWorksheets();
        $this->expectExceptionMessage('does not match the saved summary'); SavedRamaManagerWorkbook::read($path, hash_file('sha256', $path), '2098-09-01', $artifacts);
    }

    public function test_selected_original_context_is_required_but_unrelated_historical_rows_are_not(): void
    {
        [$path, $artifacts] = $this->fixture(); $original = $artifacts['retention_ldr']['path'];
        $book = IOFactory::load($original); $book->getActiveSheet()->fromArray([[999, 'Old Client', 'Old Agent', null, 'DROPPED', 1000, 0, null, null, null, null, null, null, null, '2098-08-01']], null, 'A3');
        (new Xlsx($book))->save($original); $book->disconnectWorksheets(); $artifacts['retention_ldr']['sha256'] = hash_file('sha256', $original);
        self::assertSame(2, SavedRamaManagerWorkbook::read($path, hash_file('sha256', $path), '2098-09-01', $artifacts)['managerWorkbookEvidence']['clientCount']);
    }
}
