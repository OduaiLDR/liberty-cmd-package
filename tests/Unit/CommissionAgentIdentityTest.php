<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\BonusFormatter;
use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Services\RetentionAgentIdentity;
use Cmd\Reports\Services\RetentionCommissionTierStore;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_retention_aliases_are_canonicalized_before_lookback_totals(): void
    {
        $rows = RetentionAgentIdentity::canonicalizeRows([
            ['RETENTION_AGENT' => 'ANDREA MENDOZE', 'RETENTION_COMMISSION' => 125.25],
            ['RETENTION_AGENT' => 'Andrea Mendoza', 'RETENTION_COMMISSION' => 40.00],
            ['RETENTION_AGENT' => 'ANDREA GALVES', 'RETENTION_COMMISSION' => 18.00],
            ['RETENTION_AGENT' => 'Andrea Galvez', 'RETENTION_COMMISSION' => 2.00],
            ['RETENTION_AGENT' => 'ALEPH BOLANOS', 'RETENTION_COMMISSION' => 30.00],
            ['RETENTION_AGENT' => 'ALEPH BOLAÑOS', 'RETENTION_COMMISSION' => 8.00],
            ['RETENTION_AGENT' => 'Aleph Bolaños', 'RETENTION_COMMISSION' => 4.00],
        ]);
        $this->assertSame(
            ['Andrea Mendoza', 'Andrea Mendoza', 'Andrea Galvez', 'Andrea Galvez', 'Aleph Bolaños', 'Aleph Bolaños', 'Aleph Bolaños'],
            array_column($rows, 'RETENTION_AGENT')
        );

        $totals = BonusFormatter::commissionTotals($rows);
        $this->assertCount(3, $totals);
        $this->assertSame(165.25, $totals['andrea mendoza']['commission']);
        $this->assertSame(20.0, $totals['andrea galvez']['commission']);
        $this->assertSame(42.0, $totals['aleph bolaños']['commission']);
        $this->assertSame('Aleph Bolaños', $totals['aleph bolaños']['name']);
        $this->assertSame(
            RetentionCommissionTierStore::tierMapKey('2026-04-01', 'Aleph Bolaños'),
            RetentionCommissionTierStore::tierMapKey('2026-04-01', 'ALEPH BOLAÑOS')
        );
    }

    public function test_retention_aliases_are_exact_and_do_not_enable_fuzzy_name_matching(): void
    {
        $this->assertSame('Aleph Bolañoz', RetentionAgentIdentity::canonicalName('Aleph Bolañoz'));
        $this->assertSame('Andrea Mendoz', RetentionAgentIdentity::canonicalName('Andrea Mendoz'));
        $this->assertSame('Andrea Mendoza', RetentionAgentIdentity::canonicalName("  ANDREA   MENDOZE  "));
        $this->assertSame('Aleph Bolaños', RetentionAgentIdentity::canonicalName('ALEPH BOLAÑOS'));
        $this->assertSame(
            ['Andrea Mendoza', 'Aleph Bolaños'],
            RetentionAgentIdentity::canonicalizeNames(['Andrea Mendoze', 'Andrea Mendoza', 'Aleph Bolanos', 'Aleph Bolaños'])
        );
    }

    public function test_lookback_totals_still_merge_direct_alias_rows_for_safe_persistence(): void
    {
        $totals = BonusFormatter::commissionTotals([
            ['RETENTION_AGENT' => 'ANDREA MENDOZE', 'RETENTION_COMMISSION' => 125.25],
            ['RETENTION_AGENT' => 'Andrea Mendoza', 'RETENTION_COMMISSION' => 40.00],
        ]);
        $this->assertCount(1, $totals);
        $this->assertSame('Andrea Mendoza', $totals['andrea mendoza']['name']);
        $this->assertSame(165.25, $totals['andrea mendoza']['commission']);
    }

    public function test_lookback_agent_filter_alias_renders_canonical_summary_and_employee_details(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retention-agent-alias-' . bin2hex(random_bytes(8));
        $originalContainer = Container::getInstance();
        $originalLog = Log::getFacadeRoot();
        mkdir($root . DIRECTORY_SEPARATOR . 'app', 0777, true);
        Container::setInstance(new class($root) extends Container {
            public function __construct(private string $testStorage) {}

            public function storagePath(string $path = ''): string
            {
                return $this->testStorage . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
            }
        });
        Log::swap(new NullLogger);

        $file = null;
        try {
            $file = (new BonusFormatter)->buildWorkbook(
                [[
                    'ID' => '900001', 'CLIENT' => 'Synthetic Client',
                    'RETENTION_AGENT' => 'ANDREA MENDOZE', 'RETENTION_COMMISSION' => 125.25,
                ]],
                'LDR',
                '2098-01-01',
                '2098-01-31',
                ['ANDREA MENDOZE' => ['location' => 'Jordan', 'company' => 'Lending Tower']],
                'Andrea Mendoze',
                'ldr',
                ['Andrea Mendoza']
            );

            $this->assertNotNull($file);
            $workbook = IOFactory::load($file['path']);
            try {
                $summary = $workbook->getSheetByName('Agent Summary');
                $detail = $workbook->getSheetByName('Retention Data');
                $this->assertSame('Andrea Mendoza', $summary->getCell('A2')->getValue());
                $this->assertEquals(125.25, $summary->getCell('B2')->getValue());
                $this->assertSame('Jordan', $summary->getCell('C2')->getValue());
                $this->assertSame('Lending Tower', $summary->getCell('D2')->getValue());
                $this->assertSame('Andrea Mendoza', $detail->getCell('C2')->getValue());
            } finally {
                $workbook->disconnectWorksheets();
            }
        } finally {
            if (is_array($file) && is_file($file['path'])) {
                unlink($file['path']);
            }
            rmdir($root . DIRECTORY_SEPARATOR . 'app');
            rmdir($root);
            Container::setInstance($originalContainer);
            if ($originalLog === null) {
                Log::clearResolvedInstance('log');
            } else {
                Log::swap($originalLog);
            }
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
