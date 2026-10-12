<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;

final class NsfClearedPaymentTierTest extends TestCase
{
    private function summary(int $assignments, int $actions, int $clears, string $agent = 'Synthetic Agent'): array
    {
        $data = [];
        for ($i = 0; $i < $assignments; $i++) {
            $data[] = [
                'ID' => (string) ($i + 1), 'AGENT' => $agent,
                'NSF_ACTION' => $i < $actions ? 'Recoup' : '',
                'NSF_RETURNED_DATE' => '2098-01-01', 'NSF_RECOUP_DATE' => '2098-01-02',
                // Every row has a cleared transaction, but only successful
                // in-window clears qualify as paid NSF recoveries.
                'CLEARED_DATE' => $i < $clears ? '2098-01-03' : '2098-02-06',
            ];
        }
        $method = new ReflectionMethod(GenerateNSFCommissionReport::class, 'buildCommissionRows');
        return $method->invoke(new GenerateNSFCommissionReport, $data, [$agent], new RecordingSqlServerConnector)[0];
    }

    public function test_50_51_100_101_clear_boundaries_do_not_use_the_150_actions(): void
    {
        foreach ([[50, 1, 2.0], [51, 2, 3.0], [100, 2, 3.0], [101, 3, 4.0]] as [$clears, $tier, $rate]) {
            $row = $this->summary(150, 150, $clears);
            $this->assertSame(150, $row['actions']);
            $this->assertSame($clears, $row['clears']);
            $this->assertSame(3, $row['actions_tier']);
            $this->assertSame($tier, $row['cleared_tier']);
            $this->assertSame($rate, $row['rate']);
            $this->assertSame($rate * $clears, $row['commission']);
        }
    }

    public function test_action_ratio_selects_an_independent_column_for_same_clear_band(): void
    {
        foreach ([[60, 1, 2.5], [120, 2, 2.75], [180, 3, 3.0]] as [$actions, $tier, $rate]) {
            $row = $this->summary(300, $actions, 51);
            $this->assertSame($tier, $row['actions_tier']);
            $this->assertSame(2, $row['cleared_tier']);
            $this->assertSame($rate, $row['rate']);
            $this->assertSame($rate * 51, $row['commission']);
        }
    }

    public function test_many_actions_and_zero_successful_clears_produce_no_payment_band(): void
    {
        $row = $this->summary(150, 150, 0);
        $this->assertSame(0, $row['cleared_tier']);
        $this->assertEquals(0, $row['rate']);
        $this->assertEquals(0, $row['commission']);
    }

    public function test_flat_rate_agents_override_both_ratio_and_payment_band(): void
    {
        foreach ([' ANTHONY  CLARK ', "lucas\tWright"] as $agent) {
            $row = $this->summary(100, 1, 1, $agent);
            $this->assertSame(0, $row['actions_tier']);
            $this->assertSame(1, $row['cleared_tier']);
            $this->assertSame(4.0, $row['rate']);
            $this->assertSame(4.0, $row['commission']);
        }
    }
}
