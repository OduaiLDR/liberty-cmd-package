<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\CustomerServiceRosterAmendment;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Real XLSX files and frozen builder, no application boot, database, source job or email. */
class CustomerServiceRosterAmendmentTest extends TestCase
{
    private string $directory;
    private const PERIOD = '2098-09-01';
    protected function setUp(): void { parent::setUp(); $this->directory = sys_get_temp_dir() . '/cs-amend-test-' . bin2hex(random_bytes(12)); mkdir($this->directory, 0700); }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
        parent::tearDown();
    }

    private function assignments(): array
    {
        return array_map(static fn ($kind) => ['reportKind' => $kind, 'source' => 'ldr', 'agentKey' => 'missing agent',
            'employeePk' => 999, 'employeeName' => 'Missing Agent', 'location' => 'Frozen Office', 'company' => 'LDR'], ['retention', 'nsf']);
    }

    private function sources(bool $alreadyPaid = false): array
    {
        $sources = [];
        $fixture = $this->directory . '/inputs-' . bin2hex(random_bytes(4));
        mkdir($fixture);
        foreach (PayrollReportArtifactArchive::SOURCE_KEYS as $key) {
            [$family, $source] = explode('_', $key);
            $book = new Spreadsheet;
            $detail = $book->getActiveSheet()->setTitle($family === 'nsf' ? 'NSF Data' : 'Retention Data');
            $known = 'Known ' . $source . ' Agent';
            $knownAmount = ['retention' => 10, 'lookback' => 2, 'nsf' => $source === 'ldr' ? 3 : 0][$family];
            $missingAmount = ['retention' => 25.55, 'lookback' => 7.25, 'nsf' => 4.50][$family];
            if ($family === 'retention') {
                $detail->fromArray([
                    ['ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results', 'Enrolled Debt', 'Cleared Payments', 'Reconsideration Date', 'Dropped Date', 'Retention Payment Date', 'T1 Rate', 'T2 Rate', 'T3 Rate', 'Cancel Request Date'],
                    [$source === 'ldr' ? 101 : 301, 'Frozen Known Client', $known, '2098-09-02', 'RETAINED', 10000, 2, null, null, '2098-09-04', 10, 20, 30, '2098-09-01'],
                ], null, 'A1');
                if ($source === 'ldr') $detail->fromArray([[102, 'Frozen Missing Client', 'Missing Agent', '2098-09-02', 'RETAINED', 10000, 2, null, null, '2098-09-04', 10, 20, 30, '2098-09-01']], null, 'A3');
                $summary = $book->createSheet()->setTitle('Commission Summary');
                $summary->fromArray([['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location', 'Company'], [$known, 1, 1, 1, 3, $knownAmount, 'Original Office', strtoupper($source)]], null, 'A1');
                $missing = ['Missing Agent', 9, 4, 4 / 9, 2, $missingAmount, 'Original Missing Office', 'LDR'];
            } elseif ($family === 'lookback') {
                $detail->fromArray([['ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results', 'Enrolled Debt', 'Reconsideration Date', 'Retained Date', 'Dropped Date', 'First Payment Date', 'Cutoff', 'Payments by Cutoff', 'Agent', 'Commission Rate', 'Violations', 'Retention Commission', 'Agent Deduction'],
                    [401, 'Frozen Known Client', $known, '2098-06-01', 'RETAINED', 10000, null, null, null, '2098-07-01', '2098-08-30', 2, 'Frozen Sales Agent', 0.38, 0, $knownAmount, 0]], null, 'A1');
                if ($source === 'ldr') $detail->fromArray([[402, 'Frozen Missing Client', 'Missing Agent', '2098-06-01', 'RETAINED', 10000, null, null, null, '2098-07-01', '2098-08-30', 2, 'Frozen Sales Agent', 0.38, 0, $missingAmount, 0]], null, 'A3');
                $summary = $book->createSheet()->setTitle('Agent Summary');
                $summary->fromArray([['Retention Agent', 'Total Commission', 'Location', 'Company'], [$known, $knownAmount, 'Original Office', strtoupper($source)]], null, 'A1');
                $missing = ['Missing Agent', $missingAmount, 'Original Missing Office', 'LDR'];
            } else {
                $detail->fromArray([['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission']], null, 'A1');
                if ($source === 'ldr') $detail->fromArray([
                    [201, $known, '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                    [202, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                    [203, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                    [204, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', false],
                    [205, 'Remaining Source Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                ], null, 'A2', true);
                $summary = $book->createSheet()->setTitle('Agent Summary');
                $summary->fromArray([['NSF Agent', 'Assignments', 'Actions', 'Ratio', 'Actions Tier', 'Cleared Tier', 'Rate', 'Clears', 'Commission', 'Location', 'Company'],
                    [$known, $source === 'ldr' ? 1 : 0, $source === 'ldr' ? 1 : 0, $source === 'ldr' ? 1 : 0, 3, 1, 3, $source === 'ldr' ? 1 : 0, $knownAmount, 'Original Office', strtoupper($source)]], null, 'A1');
                $summary->setCellValue('M1', 'Original Frozen Tier Reference');
                $missing = $alreadyPaid ? ['Missing Agent', 3, 3, 1.0, 3, 1, 2.25, 2, $missingAmount, 'Original Missing Office', 'LDR']
                    : ['Missing Agent', null, null, null, null, null, null, null, $missingAmount];
                $book->getProperties()->setCustomProperty('NSF Run ID', str_repeat($source === 'ldr' ? 'c' : 'd', 32));
                $book->getProperties()->setCustomProperty('NSF Run Status', 'FINAL');
            }
            if ($source === 'ldr') {
                if ($alreadyPaid) $summary->fromArray([$missing], null, 'A3');
                else {
                    $summary->setCellValue('A4', 'Unassigned Agents');
                    $summary->fromArray([$missing], null, 'A5');
                }
                if ($family === 'nsf') {
                    if ($alreadyPaid) $summary->setCellValue('A5', 'Unassigned Agents');
                    $summary->setCellValue('A6', 'Remaining Source Agent');
                    $summary->setCellValue('I6', 2);
                }
            }
            $detail->getStyle('A2')->getFont()->setBold(true);
            $book->getProperties()->setCustomProperty('Original Frozen Property', 'never refresh');
            $path = $fixture . '/' . $key . '.xlsx';
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $rows = [['agent' => $known, 'amount' => $knownAmount]];
            if ($source === 'ldr') $rows[] = ['agent' => 'Missing Agent', 'amount' => $missingAmount];
            if ($key === 'nsf_ldr') $rows[] = ['agent' => 'Remaining Source Agent', 'amount' => 2];
            $context = ['commissionResults' => PayrollCommissionResultSnapshot::component($family === 'nsf' ? 'nsf' : 'retention', $source,
                self::PERIOD, $family === 'lookback' ? 'bonusCommission' : 'commission', $rows,
                $family === 'nsf' ? PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS : null)];
            if ($family === 'retention') {
                $context += ['trancheContextReady' => true, 'trancheByClient' => [
                    'LLG-' . ($source === 'ldr' ? 101 : 301) => ['TRANCHE' => 'Frozen', 'CUT_OFF' => '2098-10-01', 'MADE_CUT_OFF' => true, 'BONUS_FLAG' => 'Bonus'],
                ]];
                if ($source === 'ldr') $context['trancheByClient']['LLG-102'] = $context['trancheByClient']['LLG-101'];
            }
            $sources[$key] = ['path' => $path, 'context' => $context];
        }
        return $sources;
    }

    private function mutate(array $sources, string $key, callable $change): void
    {
        $book = IOFactory::load($sources[$key]['path']);
        $change($book);
        (new Xlsx($book))->save($sources[$key]['path']);
        $book->disconnectWorksheets();
    }

    private function detail(array $file): array
    {
        $book = IOFactory::load($file['path']);
        try { return [$book->getSheet(0)->toArray(null, false, false), $book->getSheet(0)->getStyle('A2')->getFont()->getBold(), $book->getProperties()->getCustomProperties(), array_map(fn ($key) => $book->getProperties()->getCustomPropertyValue($key), $book->getProperties()->getCustomProperties())]; }
        finally { $book->disconnectWorksheets(); }
    }

    private function amended(array $sources, ?array $assignments = null): array
    {
        return (new CustomerServiceRosterAmendment)->amendSources($sources, $assignments ?? $this->assignments(), $this->directory . '/output');
    }

    public function test_promotes_frozen_components_without_repricing_or_changing_original_detail_context_or_properties(): void
    {
        $sources = $this->sources();
        $hashes = array_map(fn ($source) => hash_file('sha256', $source['path']), $sources);
        $result = $this->amended($sources);
        $this->assertSame(['retention_ldr', 'lookback_ldr', 'nsf_ldr'], $result['changedKeys']);
        $this->assertSame(['retention_ldr', 'lookback_ldr', 'nsf_ldr'], array_column($result['membershipAdditions'], 'reportKey'));
        $this->assertSame([25.55, 7.25, 4.5], array_column($result['membershipAdditions'], 'amount'));
        $this->assertSame([999, 999, 999], array_column($result['membershipAdditions'], 'employeePk'));
        foreach ($sources as $key => $source) {
            $this->assertSame($hashes[$key], hash_file('sha256', $source['path']));
            $this->assertSame($source['context'], $result['sources'][$key]['context']);
            $this->assertSame($this->detail($source), $this->detail($result['sources'][$key]['file']));
            if (!in_array($key, $result['changedKeys'], true)) $this->assertSame($hashes[$key], hash_file('sha256', $result['sources'][$key]['file']['path']));
        }
        $original = IOFactory::load($sources['retention_ldr']['path']);
        $originalMissing = $original->getSheetByName('Commission Summary')->rangeToArray('A5:H5', null, false, false)[0];
        $original->disconnectWorksheets();
        $book = IOFactory::load($result['sources']['retention_ldr']['file']['path']);
        $this->assertSame($originalMissing, $book->getSheetByName('Commission Summary')->rangeToArray('A3:H3', null, false, false)[0]);
        $book->disconnectWorksheets();
        $book = IOFactory::load($result['sources']['nsf_ldr']['file']['path']);
        $summary = $book->getSheetByName('Agent Summary');
        $this->assertSame(['Missing Agent', 3, 3, 1.0, null, null, 2.25, 2, 4.5, 'Frozen Office', 'LDR'], $summary->rangeToArray('A3:K3', null, false, false)[0]);
        $this->assertNotSame('', $summary->getComment('E3')->getText()->getPlainText());
        $this->assertSame('Original Frozen Tier Reference', $summary->getCell('M1')->getValue());
        $this->assertSame(1, count(array_filter($summary->rangeToArray('A2:A8', null, false, false), static fn ($row) => $row[0] === 'Missing Agent')));
        $this->assertContains(['Remaining Source Agent'], $summary->rangeToArray('A2:A8', null, false, false));
        $book->disconnectWorksheets();
    }

    public function test_already_paid_components_still_return_membership_proof_and_preserve_exact_bytes(): void
    {
        $sources = $this->sources(true);
        $result = $this->amended($sources);
        $this->assertSame([], $result['changedKeys']);
        $this->assertCount(3, $result['membershipAdditions']);
        foreach ($sources as $key => $source) $this->assertSame(hash_file('sha256', $source['path']), hash_file('sha256', $result['sources'][$key]['file']['path']));
    }

    public function test_already_paid_nsf_requires_original_identity_unique_clients_and_frozen_validity(): void
    {
        foreach (['sheet', 'agent', 'duplicate_id', 'missing_id', 'invalid_validity'] as $fault) {
            $sources = $this->sources(true);
            $this->mutate($sources, 'nsf_ldr', static function ($book) use ($fault) {
                $data = $book->getSheet(0);
                if ($fault === 'sheet') $book->removeSheetByIndex(0);
                if ($fault === 'agent') foreach ([3, 4, 5] as $r) $data->setCellValue('B' . $r, 'Another Frozen Agent');
                if ($fault === 'duplicate_id') $data->setCellValue('A4', 202);
                if ($fault === 'missing_id') $data->setCellValue('A3', null);
                if ($fault === 'invalid_validity') $data->setCellValue('G3', 'unknown');
            });
            try { $this->amended($sources, [$this->assignments()[1]]); $this->fail('Already-paid membership still needs verified original raw NSF evidence.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('NSF', $error->getMessage()); }
        }
    }

    public function test_already_paid_nsf_counts_ratio_and_rate_must_reconcile_without_repricing_tiers(): void
    {
        foreach (['B3' => 99, 'C3' => 2, 'D3' => 0.25, 'H3' => 99, 'G3' => 999] as $cell => $value) {
            $sources = $this->sources(true);
            $this->mutate($sources, 'nsf_ldr', static function ($book) use ($cell, $value) { $book->getSheet(1)->setCellValue($cell, $value); });
            try { $this->amended($sources, [$this->assignments()[1]]); $this->fail('A paid summary cannot contradict frozen raw counts or the computed amount.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('NSF', $error->getMessage()); }
        }
        $sources = $this->sources(true);
        $this->mutate($sources, 'nsf_ldr', static function ($book) {
            $summary = $book->getSheet(1);
            $summary->setCellValue('E3', 'Original Historical Tier');
            $summary->getStyle('E3')->getFont()->setItalic(true);
        });
        $result = $this->amended($sources, [$this->assignments()[1]]);
        $this->assertSame([], $result['changedKeys']);
        $this->assertSame(hash_file('sha256', $sources['nsf_ldr']['path']), hash_file('sha256', $result['sources']['nsf_ldr']['file']['path']));
        $this->assertSame(4.5, $result['membershipAdditions'][0]['amount']);
    }

    public function test_absent_zero_retention_component_can_stay_absent_while_lookback_is_promoted(): void
    {
        $sources = $this->sources();
        $sources['retention_ldr']['context']['commissionResults']['rows'] = array_values(array_filter($sources['retention_ldr']['context']['commissionResults']['rows'], static fn ($row) => $row['agentKey'] !== 'missing agent'));
        $this->mutate($sources, 'retention_ldr', static function ($book) { $book->getSheetByName('Commission Summary')->removeRow(4, 2); });
        $result = $this->amended($sources, [$this->assignments()[0]]);
        $this->assertSame(['lookback_ldr'], $result['changedKeys']);
        $this->assertSame(['lookback_ldr'], array_column($result['membershipAdditions'], 'reportKey'));
        $this->assertSame($sources['retention_ldr']['context'], $result['sources']['retention_ldr']['context']);
    }

    public function test_signed_frozen_components_are_promoted_without_changing_their_amounts(): void
    {
        $sources = $this->sources();
        foreach (['retention_ldr' => -25.55, 'lookback_ldr' => -7.25] as $key => $amount) {
            foreach ($sources[$key]['context']['commissionResults']['rows'] as &$row) if ($row['agentKey'] === 'missing agent') $row['amount'] = $amount;
            unset($row);
            $this->mutate($sources, $key, static function ($book) use ($key, $amount) {
                $book->getSheet(1)->setCellValue($key === 'retention_ldr' ? 'F5' : 'B5', $amount);
                if ($key === 'lookback_ldr') $book->getSheet(0)->setCellValue('P3', $amount);
            });
        }
        $result = $this->amended($sources, [$this->assignments()[0]]);
        $this->assertSame([-25.55, -7.25], array_column($result['membershipAdditions'], 'amount'));
        foreach (['retention_ldr', 'lookback_ldr'] as $key) $this->assertSame($sources[$key]['context'], $result['sources'][$key]['context']);
    }

    public function test_explicit_zero_component_does_not_require_promotion_or_invent_membership_proof(): void
    {
        $sources = $this->sources();
        foreach ($sources['retention_ldr']['context']['commissionResults']['rows'] as &$row) if ($row['agentKey'] === 'missing agent') $row['amount'] = 0;
        unset($row);
        $this->mutate($sources, 'retention_ldr', static function ($book) { $book->getSheet(1)->removeRow(4, 2); });
        $result = $this->amended($sources, [$this->assignments()[0]]);
        $this->assertSame(['lookback_ldr'], $result['changedKeys']);
        $this->assertSame(['lookback_ldr'], array_column($result['membershipAdditions'], 'reportKey'));
        $this->assertSame($sources['retention_ldr']['context'], $result['sources']['retention_ldr']['context']);
    }

    public function test_nonzero_retention_or_lookback_without_original_summary_detail_fails_closed(): void
    {
        foreach (['retention_ldr', 'lookback_ldr'] as $key) {
            $sources = $this->sources();
            $this->mutate($sources, $key, static function ($book) { $book->getSheet(1)->removeRow(4, 2); });
            try { $this->amended($sources); $this->fail('Missing priced detail must not be invented.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('no original summary detail', $error->getMessage()); }
        }
    }

    public function test_nonzero_components_require_matching_raw_employee_rows_even_when_already_paid(): void
    {
        foreach ([false, true] as $alreadyPaid) foreach (['retention_ldr', 'lookback_ldr'] as $key) foreach (['sheet', 'agent'] as $fault) {
            $sources = $this->sources($alreadyPaid);
            $this->mutate($sources, $key, static function ($book) use ($fault) {
                if ($fault === 'sheet') $book->removeSheetByIndex(0);
                else $book->getSheet(0)->setCellValue('C3', 'Different Frozen Employee');
            });
            try { $this->amended($sources, [$this->assignments()[0]]); $this->fail('A nonzero component needs its original employee raw detail.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('raw', $error->getMessage()); }
        }
    }

    public function test_lookback_frozen_priced_lines_must_sum_to_the_component_without_reapplying_policy(): void
    {
        $sources = $this->sources();
        $this->mutate($sources, 'lookback_ldr', static function ($book) {
            $detail = $book->getSheet(0);
            $detail->setCellValue('N3', 99);
            $detail->setCellValue('O3', 1);
            $detail->setCellValue('Q3', 1000);
        });
        $result = $this->amended($sources, [$this->assignments()[0]]);
        $this->assertSame(7.25, $result['membershipAdditions'][1]['amount']);
        foreach ([false, true] as $alreadyPaid) {
            $sources = $this->sources($alreadyPaid);
            $this->mutate($sources, 'lookback_ldr', static function ($book) { $book->getSheet(0)->setCellValue('P3', 999); });
            try { $this->amended($sources, [$this->assignments()[0]]); $this->fail('Different frozen line amounts must fail.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('priced line amounts differ', $error->getMessage()); }
        }
    }

    public function test_amount_disagreement_or_duplicate_identity_cannot_be_promoted(): void
    {
        foreach ([false, true] as $duplicate) {
            $sources = $this->sources();
            $this->mutate($sources, 'retention_ldr', static function ($book) use ($duplicate) {
                $sheet = $book->getSheet(1);
                if ($duplicate) $sheet->fromArray([['Missing Agent', 9, 4, 4 / 9, 2, 25.55]], null, 'A3');
                else $sheet->setCellValue('F5', 999);
            });
            try { $this->amended($sources); $this->fail('Inconsistent frozen evidence cannot be promoted.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString($duplicate ? 'ambiguous' : 'amount differs', $error->getMessage()); }
        }
    }

    public function test_missing_invalid_or_unreconcilable_frozen_nsf_evidence_is_blocked(): void
    {
        foreach (['missing', 'invalid_flag', 'no_clears', 'rate'] as $case) {
            $sources = $this->sources();
            if ($case === 'rate') foreach ($sources['nsf_ldr']['context']['commissionResults']['rows'] as &$row) if ($row['agentKey'] === 'missing agent') $row['amount'] = 4.51;
            $this->mutate($sources, 'nsf_ldr', static function ($book) use ($case) {
                $data = $book->getSheet(0);
                if ($case === 'missing') $data->removeRow(3, 3);
                if ($case === 'invalid_flag') $data->setCellValue('G3', 'perhaps');
                if ($case === 'no_clears') { $data->setCellValue('G3', false); $data->setCellValue('G4', false); }
                if ($case === 'rate') $book->getSheet(1)->setCellValue('I5', 4.51);
            });
            try { $this->amended($sources); $this->fail('Frozen NSF evidence must reconcile.'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_assignment_metadata_cannot_supply_prices_aliases_blank_metadata_or_duplicate_mappings(): void
    {
        $sources = $this->sources();
        $valid = $this->assignments()[0];
        foreach ([['amount' => 999], ['employeePk' => 0], ['employeePk' => '999'], ['employeeName' => 'Another Agent'], ['agentKey' => 'Missing Agent'], ['company' => ''], ['location' => ' ']] as $invalid) {
            try { $this->amended($sources, [$invalid + $valid]); $this->fail('Only canonical verified identity metadata is accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->amended($sources, [$valid, $valid]);
    }

    public function test_verified_both_source_employee_keeps_employer_company_and_each_original_priced_amount(): void
    {
        $sources = $this->sources();
        $sources['nsf_plaw']['context']['commissionResults']['rows'][] = ['agent' => 'Missing Agent', 'agentKey' => 'missing agent', 'amount' => 7.5];
        $this->mutate($sources, 'nsf_plaw', static function ($book) {
            $book->getSheet(0)->fromArray([
                [501, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                [502, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
                [503, 'Missing Agent', '1990-01-01', 'Frozen Action', '1990-02-01', '1989-12-01', true],
            ], null, 'A2', true);
            $summary = $book->getSheet(1);
            $summary->setCellValue('A4', 'Unassigned Agents');
            $summary->setCellValue('A5', 'Missing Agent');
            $summary->setCellValue('I5', 7.5);
        });
        $assignment = ['company' => 'PLAW'] + $this->assignments()[1];
        $result = $this->amended($sources, [$assignment, ['source' => 'plaw'] + $assignment]);
        $this->assertSame(['nsf_ldr', 'nsf_plaw'], $result['changedKeys']);
        $this->assertSame([999, 999], array_column($result['membershipAdditions'], 'employeePk'));
        $this->assertSame([4.5, 7.5], array_column($result['membershipAdditions'], 'amount'));
        foreach (['ldr', 'plaw'] as $source) {
            $key = 'nsf_' . $source;
            $this->assertSame($sources[$key]['context'], $result['sources'][$key]['context']);
            $book = IOFactory::load($result['sources'][$key]['file']['path']);
            $summary = $book->getSheetByName('Agent Summary');
            $this->assertSame('PLAW', $summary->getCell('K3')->getValue());
            $this->assertSame($source === 'ldr' ? 4.5 : 7.5, $summary->getCell('I3')->getValue());
            if ($source === 'ldr') {
                $this->assertSame('FFFF0000', $summary->getStyle('K3')->getFill()->getStartColor()->getARGB());
                $this->assertSame('FFFFFFFF', $summary->getStyle('K3')->getFont()->getColor()->getARGB());
                $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE, $summary->getStyle('J3')->getFill()->getFillType());
            } else $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE, $summary->getStyle('K3')->getFill()->getFillType());
            $book->disconnectWorksheets();
        }
    }

    public function test_identical_retries_have_identical_artifact_hashes_and_do_not_modify_existing_outputs(): void
    {
        $sources = $this->sources();
        $first = $this->amended($sources);
        $second = $this->amended($sources);
        foreach ($first['sources'] as $key => $source) {
            $this->assertNotSame($source['file']['path'], $second['sources'][$key]['file']['path']);
            $this->assertSame(hash_file('sha256', $source['file']['path']), hash_file('sha256', $second['sources'][$key]['file']['path']));
        }
        $this->assertSame($first['membershipAdditions'], $second['membershipAdditions']);
    }

    private function builder(): GenerateRetentionManagerCommission
    {
        $builder = new GenerateRetentionManagerCommission;
        $builder->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        return $builder;
    }

    public function test_leadership_uses_frozen_validity_and_supplied_frozen_rules_for_json_and_workbook(): void
    {
        $sources = $this->sources();
        $rules = GenerateRetentionManagerCommission::currentPayrollCalculationContext();
        $rules['nsfTeamLeader']['clearThresholds'] = [1, 2, 3];
        $builder = $this->builder();
        $before = $builder->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-09-30', $sources, $this->directory . '/before', $rules);
        $this->assertSame(1, $before['reports']['nsf_team_leader']['summary']['clearCount']);
        $this->assertSame(0.4, $before['reports']['nsf_team_leader']['summary']['totalPay']);
        $result = $this->amended($sources, [$this->assignments()[1]]);
        $amended = array_map(static fn ($source) => ['path' => $source['file']['path'], 'context' => $source['context']], $result['sources']);
        $after = $builder->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-09-30', $amended, $this->directory . '/after', $rules);
        $this->assertSame($rules, $after['calculationContext']);
        $this->assertArrayNotHasKey('calculationContext', $after['reports']['nsf_team_leader']);
        $this->assertSame($before['reports']['retention_manager'], $after['reports']['retention_manager']);
        $this->assertSame($before['reports']['retention_team_leader'], $after['reports']['retention_team_leader']);
        $summary = $after['reports']['nsf_team_leader']['summary'];
        $this->assertSame(4, $summary['assignedCount']);
        $this->assertSame(3, $summary['clearCount']);
        $this->assertSame(0.7, $summary['commissionRate']);
        $this->assertSame(2.1, $summary['totalPay']);
        $book = IOFactory::load($after['files']['nsf_team_leader']['path']);
        $this->assertSame(3.0, $book->getSheetByName('Commission Summary')->getCell('B6')->getValue());
        $this->assertSame(2.1, $book->getSheetByName('Commission Summary')->getCell('B8')->getValue());
        $book->disconnectWorksheets();
        $rate = new \ReflectionMethod($builder, 'anthonyRate');
        $this->assertSame(0.0, $rate->invoke($builder, 1.0, 3.0), 'Supplied frozen rules must not escape their bundle build.');
    }

    public function test_missing_validity_cannot_fall_back_to_today_eligibility(): void
    {
        $sources = $this->sources();
        $this->mutate($sources, 'nsf_ldr', static function ($book) { $book->getSheet(0)->setCellValue('G2', null); });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Valid Commission evidence');
        $this->builder()->buildPayrollReviewWorkbookBundle(self::PERIOD, '2098-09-30', $sources, $this->directory . '/invalid');
    }

    public function test_missing_or_malformed_frozen_rules_are_never_silently_replaced(): void
    {
        $base = GenerateRetentionManagerCommission::currentPayrollCalculationContext();
        $invalid = [$base + ['unknown' => 1], ['version' => 'future'] + $base];
        foreach ([['actionRatioThresholds' => [0.55, 0.4]], ['clearThresholds' => [1, 1, 3]], ['rates' => [[0, 0, 0]]], ['rates' => [[0, 0, INF], [0, 0, 0], [0, 0, 0]]]] as $change) {
            $next = $base;
            $next['nsfTeamLeader'] = $change + $next['nsfTeamLeader'];
            $invalid[] = $next;
        }
        foreach ($invalid as $rules) {
            try { GenerateRetentionManagerCommission::validatePayrollCalculationContext($rules); $this->fail('Invalid frozen rules must fail.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
