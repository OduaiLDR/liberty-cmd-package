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
        yield 'odd bi-weekly count keeps its half payment' => [9, 'Bi-Weekly', 4.5];
        yield 'semi-monthly preserves fractional counts' => [9, 'Semi-Monthly', 4.5];
        yield 'weekly uses four periods per month without rounding' => [5, 'Weekly', 1.25];
        yield 'monthly retains raw count' => [8, 'Monthly', 8];
        yield 'unknown frequency retains raw count' => [8, 'Every 28 Days', 8];
        yield 'blank frequency retains raw count' => [8, '', 8];
        yield 'zero remains zero' => [0, 'Bi-Weekly', 0];
    }

    #[DataProvider('frequencyCases')]
    public function test_payment_count_is_normalized_for_frequency(int $rawCount, string $frequency, int|float $expected): void
    {
        $command = new SyncEnrollmentData();
        $normalize = new ReflectionMethod(SyncEnrollmentData::class, 'normalizePaymentCount');

        self::assertSame($expected, $normalize->invoke($command, $rawCount, $frequency));
    }

    public function test_sql_payment_count_format_preserves_fractional_values(): void
    {
        $command = new SyncEnrollmentData();
        $format = new ReflectionMethod(SyncEnrollmentData::class, 'formatPaymentCount');

        self::assertSame('4.5', $format->invoke($command, 4.5));
        self::assertSame('4', $format->invoke($command, 4.0));
    }
}
