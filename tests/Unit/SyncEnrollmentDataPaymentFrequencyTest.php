<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncEnrollmentData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once getenv('DEBT_TEST_COMMAND') ?: dirname(__DIR__, 2) . '/src/Console/Commands/SyncEnrollmentData.php';

class SyncEnrollmentDataPaymentFrequencyTest extends TestCase
{
    public static function frequencyCases(): iterable
    {
        yield 'bi-weekly is handled before weekly substring' => [8, 'Bi-Weekly', 4];
        yield 'biweekly spelling is supported' => [8, 'biweekly', 4];
        yield 'semi-monthly uses two periods per month' => [9, 'Semi-Monthly', 5];
        yield 'weekly uses four periods per month' => [8, 'Weekly', 2];
        yield 'monthly retains raw count' => [8, 'Monthly', 8];
        yield 'unknown frequency retains raw count' => [8, 'Every 28 Days', 8];
        yield 'blank frequency retains raw count' => [8, '', 8];
        yield 'zero remains zero' => [0, 'Bi-Weekly', 0];
    }

    #[DataProvider('frequencyCases')]
    public function test_payment_count_is_normalized_for_frequency(int $rawCount, string $frequency, int $expected): void
    {
        $command = new SyncEnrollmentData();
        $normalize = new ReflectionMethod(SyncEnrollmentData::class, 'normalizePaymentCount');

        self::assertSame($expected, $normalize->invoke($command, $rawCount, $frequency));
    }
}
