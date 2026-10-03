<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use App\Services\CommissionBreakdownService;
use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;

final class NsfBreakdownParityTest extends TestCase
{
    public function test_report_and_payroll_details_share_confirmed_clear_tiers(): void
    {
        foreach ([50 => 2.0, 51 => 3.0, 100 => 3.0, 101 => 4.0] as $clears => $expectedRate) {
            $rows = [];
            for ($i = 0; $i < 150; $i++) $rows[] = ['ID' => (string) ($i + 1), 'AGENT' => 'Synthetic Agent',
                'NSF_RETURNED_DATE' => '2098-01-01', 'NSF_ACTION' => 'Recoup',
                'NSF_RECOUP_DATE' => '2098-01-02', 'CLEARED_DATE' => $i < $clears ? '2098-01-03' : null];
            $command = new GenerateNSFCommissionReport;
            $summary = (new ReflectionMethod($command, 'buildCommissionRows'))->invoke($command,
                $rows, ['Synthetic Agent'], new RecordingSqlServerConnector)[0];
            $detail = (new ReflectionMethod(CommissionBreakdownService::class, 'nsfFromRows'))->invoke(null,
                'ldr', '2098-01-01', '2098-01-31', ' Synthetic   Agent ', $rows);
            $this->assertSame($expectedRate, (float) $summary['rate']);
            $this->assertSame($expectedRate, (float) $detail['summary']['rate']);
            $this->assertEquals($clears * $expectedRate, $summary['commission']);
            $this->assertEquals($summary['commission'], $detail['summary']['commission']);
            $this->assertSame($clears, $detail['summary']['clears']);
            $this->assertCount(150, $detail['lines']);
        }
    }

    public function test_generator_manager_fallback_and_details_use_identical_source_query(): void
    {
        $cfg = ['custom_agent' => 742134, 'custom_nsf_return' => 742148, 'custom_nsf_action' => 742136, 'custom_nsf_recoup' => 742146];
        $source = new NsfParityConnector;
        $command = new GenerateNSFCommissionReport;
        $generator = (new ReflectionMethod($command, 'fetchNSFRows'))->invoke($command, $source, $cfg, '2098-01-01', '2098-01-31');
        $details = (new ReflectionMethod(CommissionBreakdownService::class, 'fetchNsfRows'))->invoke(null, $source, $cfg, '2098-01-01', '2098-01-31', 'Synthetic Agent');
        $manager = new GenerateRetentionManagerCommission;
        $fallback = (new ReflectionMethod($manager, 'fetchNsfCommissionRows'))->invoke($manager, $source,
            ['agent' => 742134, 'returned' => 742148, 'action' => 742136, 'recoup' => 742146], '2098-01-01', '2098-02-01');
        $this->assertSame($generator, $details);
        $this->assertSame($generator, $fallback);
        $this->assertSame($source->queries[0], $source->queries[1]);
        $this->assertSame($source->queries[0], $source->queries[2]);
    }
}

final class NsfParityConnector extends DBConnector
{
    public array $queries = [];
    public function __construct() {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->queries[] = $sql;
        return ['data' => [['ID' => '100', 'AGENT' => 'Synthetic Agent', 'NSF_RETURNED_DATE' => '2098-01-01',
            'NSF_ACTION' => 'Recoup', 'NSF_RECOUP_DATE' => '2098-01-02', 'CLEARED_DATE' => '2098-01-03',
            'AGENT_VARIANTS' => 1, 'RETURN_VARIANTS' => 1, 'ACTION_VARIANTS' => 1, 'RECOUP_VARIANTS' => 1,
            'CLEAR_VARIANTS' => 1, 'PAYMENT_PRESENT' => 1]], 'rowCount' => 1, 'columns' => []];
    }
}
