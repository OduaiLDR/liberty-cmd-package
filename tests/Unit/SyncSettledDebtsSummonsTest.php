<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncSettledDebtsData;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SyncSettledDebtsSummonsTest extends TestCase
{
    public function test_original_summons_normalization_is_preserved(): void
    {
        $command = new SyncSettledDebtsData();
        $method = new \ReflectionMethod($command, 'boolToString');
        foreach ([false, 0, '0', 'False', 'no', ' N ', null, '', ' ', 'unknown'] as $value) {
            self::assertSame('False', $method->invoke($command, $value));
        }
        foreach ([true, 1, '1', 'TRUE', 'yes', ' Y ', 2, '2', 0.5] as $value) {
            self::assertSame('True', $method->invoke($command, $value));
        }
    }

    public function test_original_summons_and_pre_lit_strings_are_inserted_unchanged(): void
    {
        $command = new SyncSettledDebtsData();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $db = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()
            ->onlyMethods(['querySqlServer'])->getMock();
        $db->expects(self::once())->method('querySqlServer')->willReturnCallback(function (string $sql): array {
            self::assertStringContainsString("'acct', 'False', 'False', 'DP_LDR'", $sql);
            return ['success' => true];
        });
        $method = new \ReflectionMethod($command, 'insertNegotiatorDebts');
        self::assertSame(1, $method->invoke($command, $db, [[
            'debt_id' => '123', 'settlement_date' => null, 'last_payment_date' => null,
            'account_number' => 'acct', 'has_summons' => 'False', 'pre_lit' => 'False',
        ]], 'LDR'));
    }
}
