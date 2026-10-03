<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\BonusFormatter;
use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use ReflectionMethod;

class CommissionAgentIdentityTest extends TestCase
{
    public function test_lookback_workbook_totals_and_persisted_amounts_share_normalized_identity(): void
    {
        $totals = BonusFormatter::commissionTotals([
            ['RETENTION_AGENT' => 'Alice Smith', 'RETENTION_COMMISSION' => 50],
            ['RETENTION_AGENT' => " ALICE \t Smith ", 'RETENTION_COMMISSION' => 70],
            ['RETENTION_AGENT' => 'Bob Jones', 'RETENTION_COMMISSION' => 35],
            ['RETENTION_AGENT' => ' ', 'RETENTION_COMMISSION' => 999],
        ]);
        $this->assertCount(2, $totals);
        $this->assertSame(120.0, $totals['alice smith']['commission']);
        $this->assertSame(35.0, $totals['bob jones']['commission']);
        $this->assertSame('Alice Smith', $totals['alice smith']['name']);

        // Same projection as the generator; recording connector cannot reach Azure.
        $rows = array_values(array_map(static fn (array $total): array => [
            'agent' => $total['name'], 'amount' => round($total['commission'], 2),
        ], $totals));
        $sql = new RecordingSqlServerConnector;
        Log::swap(new NullLogger);
        try {
            $result = CommissionResultsWriter::persist($sql, 'retention', 'ldr', '2098-01-01', 'Bonus_Commission', $rows);
            $this->assertSame(['attempted' => 2, 'written' => 2, 'failed' => 0], $result);
            $merges = $sql->callsMatching('/^MERGE/');
            $this->assertCount(2, $merges);
            $this->assertSame(['retention', 'ldr', '2098-01-01', 'Alice Smith', 120.0], $merges[0]['params']);
            $this->assertSame(['retention', 'ldr', '2098-01-01', 'Bob Jones', 35.0], $merges[1]['params']);
        } finally {
            Log::clearResolvedInstance('log');
        }
    }

    public function test_nsf_combines_identity_before_tier_selection_and_preserves_roster_spelling(): void
    {
        $recovered = static fn (string $name): array => [
            'AGENT' => $name, 'NSF_ACTION' => 'Recoup',
            'NSF_RETURNED_DATE' => '2098-01-01',
            'NSF_RECOUP_DATE' => '2098-01-02', 'CLEARED_DATE' => '2098-01-03',
        ];
        $data = array_fill(0, 50, $recovered('ALICE SMITH'));
        $data[] = $recovered(" Alice \t Smith ");
        $data[] = $recovered('BOB JONES');
        $sql = new RecordingSqlServerConnector([
            '/SELECT Employee_Name/' => ['success' => true, 'data' => [
                ['Employee_Name' => 'ALICE SMITH', 'Location' => 'Jordan', 'Company' => 'Lending Tower'],
                ['Employee_Name' => 'Bob Jones', 'Location' => 'Guatemala', 'Company' => 'Liberty Debt Relief'],
            ]],
        ]);
        $method = new ReflectionMethod(GenerateNSFCommissionReport::class, 'buildCommissionRows');
        $rows = $method->invoke(new GenerateNSFCommissionReport, $data,
            ['Alice Smith', 'Bob Jones', 'ALICE SMITH', " Alice \t Smith ", 'BOB JONES', ' '], $sql);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice Smith', $rows[0]['agent']);
        $this->assertSame(51, $rows[0]['assignments']);
        $this->assertSame(51, $rows[0]['clears']);
        $this->assertSame(2, $rows[0]['cleared_tier']);
        $this->assertSame(153.0, $rows[0]['commission']);
        $this->assertSame('Jordan', $rows[0]['location']);
        $this->assertSame('Lending Tower', $rows[0]['company']);
        $this->assertSame('Bob Jones', $rows[1]['agent']);
        $this->assertSame(2.0, $rows[1]['commission']);
        $this->assertSame('Guatemala', $rows[1]['location']);
    }
}
