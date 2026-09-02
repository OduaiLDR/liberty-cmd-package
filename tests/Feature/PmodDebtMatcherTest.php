<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Support\PmodDebtMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Debt shapes here mirror a real Forth record, read off production 2026-09-02:
 * the creditor is a nested object, and the debt's own id is unrelated to the
 * creditor id.
 */
class PmodDebtMatcherTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function debt(string $id, string $creditorId, string $creditorName, array $overrides = []): array
    {
        return array_merge([
            'object' => 'debt',
            'id' => $id,
            'creditor' => ['object' => 'creditors', 'id' => $creditorId, 'company_name' => $creditorName],
            'og_account_num' => '',
            'creditor_account_num' => '',
            'original_debt_amount' => '0.00',
            'current_debt_amount' => '0.00',
            'enrolled' => '0',
        ], $overrides);
    }

    public function testMatchesASingleCreditorByName(): void
    {
        $result = PmodDebtMatcher::match(
            [$this->debt('148442930', '2435', 'Target'), $this->debt('148442931', '9001', 'LABCORP')],
            ['creditor_name' => 'Target'],
        );

        $this->assertSame('matched', $result['reason']);
        $this->assertSame('148442930', $result['debt']['id']);
    }

    /** Punctuation and case must not matter: SYNCB/ABCWH and SYNCB ABCWH are one creditor. */
    public function testNameMatchingIsNormalised(): void
    {
        $result = PmodDebtMatcher::match(
            [$this->debt('1', '2435', 'SYNCB/ABCWH')],
            ['creditor_name' => 'syncb abcwh'],
        );

        $this->assertSame('1', $result['debt']['id']);
    }

    /** The claimed id is matched against the debt's creditor id, not the debt id. */
    public function testMatchesByCreditorIdNotDebtId(): void
    {
        $result = PmodDebtMatcher::match(
            [$this->debt('148442930', '2435', 'Target')],
            ['creditor_id' => '2435'],
        );

        $this->assertSame('148442930', $result['debt']['id']);
    }

    public function testDebtIdIsNotTreatedAsACreditorId(): void
    {
        $result = PmodDebtMatcher::match(
            [$this->debt('148442930', '2435', 'Target')],
            ['creditor_id' => '148442930'],
        );

        $this->assertNull($result['debt']);
        $this->assertSame('creditor_not_on_contact', $result['reason']);
    }

    /**
     * The case that made the old matcher dangerous: two accounts with the same
     * creditor. Matching on name alone returned the first and would have deleted
     * an arbitrary one of them.
     */
    public function testTwoAccountsSameCreditorAreSeparatedByAccountNumber(): void
    {
        $debts = [
            $this->debt('1', '2435', 'Target', ['og_account_num' => 'AAA111', 'current_debt_amount' => '500.00']),
            $this->debt('2', '2435', 'Target', ['og_account_num' => 'BBB222', 'current_debt_amount' => '900.00']),
        ];

        $result = PmodDebtMatcher::match($debts, ['creditor_name' => 'Target', 'account_number' => 'BBB222']);

        $this->assertSame('2', $result['debt']['id']);
    }

    public function testTwoAccountsSameCreditorAreSeparatedByBalance(): void
    {
        $debts = [
            $this->debt('1', '2435', 'Target', ['current_debt_amount' => '500.00']),
            $this->debt('2', '2435', 'Target', ['current_debt_amount' => '1993.00']),
        ];

        // The portal sends "$1993"; it must still line up with "1993.00".
        $result = PmodDebtMatcher::match($debts, ['creditor_name' => 'Target', 'balance' => '$1993']);

        $this->assertSame('2', $result['debt']['id']);
    }

    /** Nothing distinguishes them, so refuse rather than delete an arbitrary one. */
    public function testAmbiguousMatchFailsClosed(): void
    {
        $debts = [
            $this->debt('1', '2435', 'Target'),
            $this->debt('2', '2435', 'Target'),
        ];

        $result = PmodDebtMatcher::match($debts, ['creditor_name' => 'Target']);

        $this->assertNull($result['debt']);
        $this->assertSame('ambiguous_creditor_match', $result['reason']);
        $this->assertSame(2, $result['candidates']);
    }

    /** Enrolled debts are the ones in the program, so they win a tie. */
    public function testEnrolledDebtIsPreferredOverUnenrolled(): void
    {
        $debts = [
            $this->debt('1', '2435', 'Target', ['enrolled' => '0']),
            $this->debt('2', '2435', 'Target', ['enrolled' => '1']),
        ];

        $result = PmodDebtMatcher::match($debts, ['creditor_name' => 'Target']);

        $this->assertSame('2', $result['debt']['id']);
    }

    /**
     * A stale balance in the payload must not veto an otherwise unambiguous name
     * match - narrowing only applies when it leaves something behind.
     */
    public function testStaleBalanceDoesNotVetoAnUnambiguousMatch(): void
    {
        $debts = [
            $this->debt('1', '2435', 'Target', ['current_debt_amount' => '742.19']),
            $this->debt('2', '9001', 'LABCORP'),
        ];

        $result = PmodDebtMatcher::match($debts, ['creditor_name' => 'Target', 'balance' => '1000.00']);

        $this->assertSame('1', $result['debt']['id']);
    }

    public function testUnknownCreditorReturnsNull(): void
    {
        $result = PmodDebtMatcher::match([$this->debt('1', '2435', 'Target')], ['creditor_name' => 'Citibank']);

        $this->assertNull($result['debt']);
        $this->assertSame('creditor_not_on_contact', $result['reason']);
    }

    public function testEmptyDebtListIsReportedDistinctly(): void
    {
        $result = PmodDebtMatcher::match([], ['creditor_name' => 'Target']);

        $this->assertNull($result['debt']);
        $this->assertSame('no_debts_on_contact', $result['reason']);
    }

    public function testNoCreditorIdentifierIsReportedDistinctly(): void
    {
        $result = PmodDebtMatcher::match([$this->debt('1', '2435', 'Target')], ['balance' => '100.00']);

        $this->assertNull($result['debt']);
        $this->assertSame('no_creditor_identifier', $result['reason']);
    }
}
