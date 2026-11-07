<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\CapitalReportBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CapitalReportBuilderTest extends TestCase
{
    public function test_remaining_fees_are_floored_at_zero_for_each_company(): void
    {
        $builder = new CapitalReportBuilder();
        $method = new ReflectionMethod($builder, 'applyEpfColumns');

        foreach ([[80.0, 20.0], [100.0, 0.0], [120.0, 0.0]] as [$paid, $expected]) {
            $row = [];
            foreach (['LDR', 'PLAW', 'Pro Law'] as $company) {
                $row[$company . ' EPF Original'] = 100.0;
                $row[$company . ' EPF Current'] = 100.0;
                $row[$company . ' EPF Paid'] = $paid;
            }

            $result = $method->invoke($builder, $row);

            foreach (['LDR', 'PLAW', 'Pro Law'] as $company) {
                $this->assertSame($expected, $result[$company . ' EPF Remaining']);
            }
            $this->assertSame($expected * 3, $result['Total EPF Remaining']);
        }
    }
}
