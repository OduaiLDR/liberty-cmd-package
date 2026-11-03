<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Cmd\Reports\Services\RetentionCommissionTierStore;
use Cmd\Reports\Services\RetentionPricingSnapshot;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;

final class CustomerServiceCalculationSnapshotTest extends TestCase
{
    private const PERIOD = '2098-02-01';
    private string $directory;
    private array $artifacts;
    private Container $previous;
    private mixed $previousLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/cs-calculation-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/app', 0700, true);
        $this->previous = Container::getInstance(); $this->previousLog = Log::getFacadeRoot();
        Container::setInstance(new class($this->directory) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
        Log::swap(new NullLogger);
        $this->artifacts = $this->sources();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        if ($this->previousLog !== null) Log::swap($this->previousLog); else Log::clearResolvedInstance('log');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
        parent::tearDown();
    }

    private function component(string $family, string $source, float $amount): array
    {
        return PayrollCommissionResultSnapshot::component($family === 'nsf' ? 'nsf' : 'retention', $source, self::PERIOD,
            $family === 'lookback' ? 'bonusCommission' : 'commission', [['agent' => 'Jane Doe', 'amount' => $amount],
                ['agent' => 'Other Secret Agent', 'amount' => 999], ['agent' => 'Zero Agent', 'amount' => 0]],
            $family === 'nsf' ? PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS : null);
    }

    private function sources(): array
    {
        $sources = [];
        foreach (PayrollReportArtifactArchive::SOURCE_KEYS as $key) {
            [$family, $source] = explode('_', $key);
            $book = new Spreadsheet;
            $amount = ['retention' => 10.0, 'lookback' => 7.25, 'nsf' => 4.5][$family];
            $context = ['commissionResults' => $this->component($family, $source, $amount)];
            if ($family === 'retention') {
                $book->getActiveSheet()->setTitle('Retention Data')->fromArray([
                    ['ID', 'Client', 'Retention Agent', 'Retention Date', 'Retention Payment Date', 'T1 Rate', 'T2 Rate', 'T3 Rate', 'T4 Rate', 'Applied Tier', 'Payable Commission'],
                    [101, 'Jane Private Client', ' Jane  DOE ', '2098-01-10', '2098-02-05', 10, 20, 30, 0, 1, 10],
                    [901, 'Other Private Client', 'Other Secret Agent', '2098-01-11', '2098-02-06', 999, 0, 0, 0, 1, 999],
                ], null, 'A1', true);
                $lines = [];
                foreach ([[2, '101', 'Jane Doe', '2098-01-10', '2098-02-05', 10], [3, '901', 'Other Secret Agent', '2098-01-11', '2098-02-06', 999]] as [$r, $id, $agent, $retained, $payment, $price]) {
                    $lines[] = ['detailRow' => $r, 'clientId' => $id, 'agent' => $agent, 'agentKey' => mb_strtolower($agent),
                        'retentionDate' => $retained, 'paymentDate' => $payment, 'retainedMonth' => '2098-01-01',
                        'currentTier' => 3, 'appliedTier' => 1, 'rates' => [$price, $price === 10 ? 20 : 0, $price === 10 ? 30 : 0, 0], 'amount' => $price];
                }
                $context['regularPricing'] = RetentionPricingSnapshot::capture($source, self::PERIOD, true, $lines, $context['commissionResults']);
            } elseif ($family === 'lookback') {
                $book->getActiveSheet()->setTitle('Retention Data')->fromArray([
                    ['ID', 'Client', 'Retention Agent', 'Retention Date', 'First Payment Date', 'Commission Rate', 'Retention Commission', 'Agent Deduction'],
                    [201, 'Jane Private Client', 'Jane Doe', '2097-11-10', '2097-12-01', 0.38, 3.25, 1000],
                    [202, 'Jane Other Client', 'Jane Doe', '2097-11-11', '2097-12-02', 9.99, 4.0, 2000],
                    [902, 'Other Private Client', 'Other Secret Agent', '2097-11-11', '2097-12-02', 9.99, 999, 2000],
                ], null, 'A1', true);
            } else {
                $book->getActiveSheet()->setTitle('NSF Data')->fromArray([
                    ['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission'],
                    [301, 'Jane Doe', '2098-02-01', 'Frozen action', '2098-02-02', '2098-02-03', true],
                    [302, 'Jane Doe', '2098-02-01', 'Frozen action', '2098-02-02', '2098-02-04', true],
                    [303, 'Jane Doe', '2098-02-01', '', '2098-02-02', '', false],
                    [903, 'Other Secret Agent', '2098-02-01', 'Frozen action', '2098-02-02', '2098-02-04', true],
                ], null, 'A1', true);
                $book->createSheet()->setTitle('Agent Summary')->fromArray([
                    ['NSF Agent', 'Assignments', 'Actions', 'Ratio', 'Actions Tier', 'Cleared Tier', 'Rate', 'Clears', 'Commission'],
                    ['Jane Doe', 3, 2, round(2 / 3, 4), 3, 1, 2.25, 2, 4.5],
                    ['Other Secret Agent', 1, 1, 1, 3, 1, 999, 1, 999],
                ], null, 'A1', true);
            }
            $path = $this->directory . '/' . $key . '.xlsx';
            (new Xlsx($book))->save($path); $book->disconnectWorksheets();
            $sources[$key] = ['key' => $key, 'path' => $path, 'sha256' => hash_file('sha256', $path), 'context' => $context];
        }
        return $sources;
    }

    private function read(string $kind = 'retention', string $agent = 'jane doe', string $scope = 'both'): array
    {
        return CustomerServiceCalculationSnapshot::forAgent($this->artifacts, self::PERIOD, $kind, $agent, $scope);
    }
    private function mutate(string $key, callable $edit): void
    {
        $book = IOFactory::load($this->artifacts[$key]['path']);
        try { $edit($book); (new Xlsx($book))->save($this->artifacts[$key]['path']); }
        finally { $book->disconnectWorksheets(); }
        $this->artifacts[$key]['sha256'] = hash_file('sha256', $this->artifacts[$key]['path']);
    }

    public function test_regular_and_lookback_return_only_selected_employee_original_prices_for_each_source(): void
    {
        $snapshot = $this->read();
        $this->assertSame(['retention_ldr', 'lookback_ldr', 'retention_plaw', 'lookback_plaw'], array_column($snapshot['components'], 'reportKey'));
        $this->assertSame([10.0, 7.25, 10.0, 7.25], array_column($snapshot['components'], 'amount'));
        $this->assertSame(['verified', 'verified', 'verified', 'verified'], array_column($snapshot['components'], 'status'));
        $this->assertSame(['101'], array_column($snapshot['components'][0]['lines'], 'clientId'));
        $this->assertSame([3.25, 4.0], array_column($snapshot['components'][1]['lines'], 'amount'));
        $this->assertSame(1, $snapshot['components'][0]['lines'][0]['appliedTier']);
        $this->assertStringNotContainsString('Other Secret', json_encode($snapshot));
        $this->assertStringNotContainsString('Private Client', json_encode($snapshot));
        $this->assertStringNotContainsString('999', json_encode(array_column($snapshot['components'], 'lines')));
    }

    public function test_nsf_uses_stored_selected_rate_and_frozen_valid_flags_without_recomputing_tiers(): void
    {
        $snapshot = $this->read('nsf', scope: 'ldr');
        $this->assertCount(1, $snapshot['components']);
        $component = $snapshot['components'][0];
        $this->assertSame('verified', $component['status']);
        $this->assertSame(['301', '302'], array_column($component['lines'], 'clientId'));
        $this->assertSame([2.25, 2.25], array_column($component['lines'], 'amount'));
        $this->assertSame('2098-02-03', $component['lines'][0]['paymentDate']);
        $this->assertStringNotContainsString('903', json_encode($snapshot));
    }

    public function test_legacy_regular_missing_selected_prices_is_explicitly_unavailable_but_lookback_is_verified(): void
    {
        unset($this->artifacts['retention_ldr']['context']['regularPricing']);
        $components = $this->read(scope: 'ldr')['components'];
        $this->assertSame('unavailable', $components[0]['status']); $this->assertSame([], $components[0]['lines']);
        $this->assertSame(10.0, $components[0]['amount']); $this->assertNotEmpty($components[0]['reason']);
        $this->assertSame('verified', $components[1]['status']);
    }

    public function test_zero_components_are_verified_empty_and_unknown_both_scope_adds_no_earner(): void
    {
        unset($this->artifacts['retention_ldr']['context']['regularPricing']);
        foreach ($this->read(agent: 'zero agent')['components'] as $component) {
            $this->assertSame('verified', $component['status']); $this->assertSame(0.0, $component['amount']); $this->assertSame([], $component['lines']);
        }
        $this->assertSame([], $this->read(agent: 'unknown agent')['components']);
        $this->expectException(\RuntimeException::class);
        $this->read(agent: 'unknown agent', scope: 'ldr');
    }

    public function test_source_presence_in_one_retention_component_proves_only_the_missing_counterpart_is_zero(): void
    {
        $key = 'retention_ldr';
        $context = &$this->artifacts[$key]['context'];
        $context['commissionResults']['rows'] = array_values(array_filter($context['commissionResults']['rows'], fn ($row) => $row['agentKey'] !== 'jane doe'));
        $context['regularPricing']['rows'] = array_values(array_filter($context['regularPricing']['rows'], fn ($row) => $row['agentKey'] !== 'jane doe'));
        $this->mutate($key, static fn ($book) => $book->getSheet(0)->setCellValue('C2', 'Absent Source Agent'));
        $components = $this->read(scope: 'ldr')['components'];
        $this->assertCount(2, $components); $this->assertSame(0.0, $components[0]['amount']);
        $this->assertSame([], $components[0]['lines']); $this->assertSame('verified', $components[0]['status']);
        $this->assertSame(7.25, $components[1]['amount']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableNsf')]
    public function test_missing_or_reconstructed_nsf_rate_cannot_be_allocated_from_the_total(string $reason): void
    {
        $this->mutate('nsf_ldr', static function ($book) use ($reason) {
            $sheet = $book->getSheetByName('Agent Summary');
            if ($reason === 'missing') $sheet->setCellValue('G2', null);
            else $sheet->getComment('G2')->getText()->createTextRun('Rate reconciled from the original frozen commission amount and valid clears.');
        });
        $component = $this->read('nsf', scope: 'ldr')['components'][0];
        $this->assertSame('unavailable', $component['status']); $this->assertSame([], $component['lines']);
        $this->assertSame(4.5, $component['amount']);
    }
    public static function unavailableNsf(): array { return [['missing'], ['reconstructed']]; }

    public function test_nonzero_legacy_component_without_selected_employee_detail_is_unavailable(): void
    {
        $this->mutate('lookback_ldr', static function ($book) {
            $book->getSheet(0)->setCellValue('C2', 'Another Agent');
            $book->getSheet(0)->setCellValue('C3', 'Another Agent');
        });
        $component = $this->read(scope: 'ldr')['components'][1];
        $this->assertSame('unavailable', $component['status']);
        $this->assertSame([], $component['lines']);
        $this->assertSame(7.25, $component['amount']);
    }

    public function test_duplicate_lookback_client_is_rejected_even_when_its_prices_reconcile_to_the_frozen_total(): void
    {
        // Numeric 201 and the same trimmed literal identity remain one client.
        $this->mutate('lookback_ldr', static fn ($book) => $book->getSheet(0)->setCellValue('A3', '201 '));
        $this->assertSame(7.25, $this->artifacts['lookback_ldr']['context']['commissionResults']['rows'][0]['amount']);
        $this->expectException(\RuntimeException::class);
        $this->read(scope: 'ldr');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptions')]
    public function test_invalid_archive_scope_or_selected_price_proof_throws(string $fault): void
    {
        if ($fault === 'hash') file_put_contents($this->artifacts['lookback_plaw']['path'], 'changed', FILE_APPEND);
        if ($fault === 'scope') $this->artifacts['nsf_plaw']['context']['commissionResults']['period'] = '2098-01-01';
        if ($fault === 'missing') unset($this->artifacts['nsf_plaw']);
        if ($fault === 'duplicate') { $this->artifacts = array_values($this->artifacts); $this->artifacts[5] = $this->artifacts[0]; }
        if ($fault === 'proof amount') $this->artifacts['retention_ldr']['context']['regularPricing']['rows'][0]['amount'] = 11;
        if ($fault === 'proof identity') $this->artifacts['retention_ldr']['context']['regularPricing']['rows'][0]['clientId'] = 'forged';
        if ($fault === 'component identity') {
            $this->artifacts['lookback_ldr']['context']['commissionResults']['rows'][0]['agent'] = ' Jane Doe ';
            $this->artifacts['lookback_ldr']['context']['commissionResults']['rows'][0]['agentKey'] = ' jane doe ';
        }
        if ($fault === 'lookback sum') $this->mutate('lookback_ldr', static fn ($book) => $book->getSheet(0)->setCellValue('G2', 5));
        if ($fault === 'nsf duplicate') $this->mutate('nsf_ldr', static fn ($book) => $book->getSheet(0)->setCellValue('A3', 301));
        if ($fault === 'nsf rate') $this->mutate('nsf_ldr', static fn ($book) => $book->getSheet(1)->setCellValue('G2', 5));
        $this->expectException(\RuntimeException::class);
        $this->read($fault === 'nsf duplicate' || $fault === 'nsf rate' ? 'nsf' : 'retention');
    }
    public static function corruptions(): array
    {
        return [['hash'], ['scope'], ['missing'], ['duplicate'], ['proof amount'], ['proof identity'], ['component identity'], ['lookback sum'], ['nsf duplicate'], ['nsf rate']];
    }

    public function test_identity_lookup_is_exact_and_does_not_apply_fuzzy_or_alias_matching(): void
    {
        foreach (['Jane Doe', ' jane doe ', 'jane  doe'] as $agent) {
            try { $this->read(agent: $agent); $this->fail('Noncanonical query identity accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame([], $this->read(agent: 'jane do')['components']);
    }

    public function test_actual_generator_workbook_and_capture_remain_frozen_after_later_tier_map_changes(): void
    {
        $command = new GenerateRetentionCommissionReport;
        $rows = [['ID' => '7001', 'RETENTION_AGENT' => 'Jane Doe', 'RETENTION_DATE' => '2098-01-06',
            'RETENTION_PAYMENT_DATE' => '2098-02-05', 'T1' => 10.01, 'T2' => 20.02, 'T3' => 30.03, 'T4' => 0]];
        $build = function (int $tier) use ($command, $rows): array {
            $file = (new ReflectionMethod($command, 'buildWorkbook'))->invoke($command, $rows, ['agents' => ['Jane Doe'], 'has_t4' => true],
                'LDR', self::PERIOD, '2098-02-28', [], null, [RetentionCommissionTierStore::tierMapKey('2098-01-01', 'Jane Doe') => $tier], true, false, ['Jane Doe'], 'ldr');
            $summary = (new ReflectionProperty($command, 'lastSummaryRows'))->getValue($command);
            $component = PayrollCommissionResultSnapshot::component('retention', 'ldr', self::PERIOD, 'commission', [['agent' => 'Jane Doe', 'amount' => $summary['Jane Doe']['commission']]]);
            $proof = RetentionPricingSnapshot::capture('ldr', self::PERIOD, true,
                array_values((new ReflectionProperty($command, 'lastPricingRows'))->getValue($command)), $component);
            return ['key' => 'retention_ldr', 'path' => $file['path'], 'sha256' => hash_file('sha256', $file['path']),
                'context' => ['commissionResults' => $component, 'regularPricing' => $proof]];
        };
        $this->artifacts['retention_ldr'] = $build(1);
        $original = $this->read(scope: 'ldr');
        $this->assertSame(10.01, $original['components'][0]['lines'][0]['amount']);
        $later = $build(3);
        $this->assertSame(30.03, $later['context']['regularPricing']['rows'][0]['amount']);
        $this->assertSame($original, $this->read(scope: 'ldr'));
    }
}
