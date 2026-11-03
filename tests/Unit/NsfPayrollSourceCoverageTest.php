<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;

final class NsfPayrollSourceCoverageTest extends TestCase
{
    private function payloads(array $pricedRows): array
    {
        return (new ReflectionMethod(GenerateNSFCommissionReport::class, 'commissionPayloads'))
            ->invoke(new GenerateNSFCommissionReport, $pricedRows, static fn (string $name): bool => $name === 'Roster Agent');
    }

    public function test_payroll_capture_keeps_unrostered_positive_negative_zero_and_offsetting_source_amounts(): void
    {
        $priced = [
            ['agent' => 'Roster Agent', 'commission' => 20.0, 'rate' => 2.0],
            ['agent' => 'Missing Earner', 'commission' => 999.0],
            ['agent' => 'Negative Earner', 'commission' => -12.5],
            ['agent' => 'Zero Earner', 'commission' => 0.0],
            ['agent' => 'Offset Positive', 'commission' => 40.0],
            ['agent' => 'Offset Negative', 'commission' => -40.0],
        ];
        $payloads = $this->payloads($priced);
        $this->assertSame([$priced[0]], $payloads['rosterRows'], 'Workbook and payable scope must remain roster-only.');
        foreach (['ldr', 'plaw'] as $source) {
            $captured = PayrollCommissionResultSnapshot::component('nsf', $source, '2098-09-01', 'commission', $payloads['sourceResults']);
            $this->assertSame($source, $captured['source']);
            $this->assertTrue($captured['complete']);
            $this->assertCount(6, $captured['rows']);
            $byAgent = array_column($captured['rows'], 'amount', 'agentKey');
            foreach ($priced as $row) $this->assertSame($row['commission'], $byAgent[strtolower($row['agent'])]);
        }
    }

    public function test_the_priced_source_and_payable_amounts_are_captured_without_repricing(): void
    {
        $data = [];
        foreach (['Roster Agent', 'Unrostered Agent'] as $agent) {
            for ($i = 0; $i < 51; $i++) $data[] = [
                'AGENT' => $agent, 'NSF_ACTION' => 'Recoup', 'NSF_RETURNED_DATE' => '2098-09-01',
                'NSF_RECOUP_DATE' => '2098-09-02', 'CLEARED_DATE' => '2098-09-03',
            ];
        }
        $priced = (new ReflectionMethod(GenerateNSFCommissionReport::class, 'buildCommissionRows'))
            ->invoke(new GenerateNSFCommissionReport, $data, ['Roster Agent', 'Unrostered Agent'], new RecordingSqlServerConnector);
        $payloads = $this->payloads($priced);
        $this->assertSame(3.0, $priced[0]['rate']);
        $this->assertSame(153.0, $priced[0]['commission']);
        $this->assertSame([$priced[0]], $payloads['rosterRows']);
        $captured = PayrollCommissionResultSnapshot::component('nsf', 'ldr', '2098-09-01', 'commission', $payloads['sourceResults']);
        $this->assertSame(['roster agent' => 153.0, 'unrostered agent' => 153.0], array_column($captured['rows'], 'amount', 'agentKey'));
    }
}
