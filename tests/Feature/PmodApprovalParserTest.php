<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Parsing\PmodApprovalParser;
use PHPUnit\Framework\TestCase;

class PmodApprovalParserTest extends TestCase
{
    public function testParseRawTextNormalizesExpectedFields(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Name: Green candice',
            'PMOD Approval For Customer Id: 392014299',
            'Settlement ID: 5538063 - increase_option1 - 0',
            'Action: Increase Payments',
            'Increase Payment Amount: $100.00',
            'Total Payment Amount: $368.73',
            'Start Date: 2024-11-25',
            'End Date: 2025-04-25',
            'User: Admin(Avinash)',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame('392014299', $result['data']['customer_id']);
        $this->assertSame('5538063', $result['data']['settlement_id']);
        $this->assertSame(['5538063'], $result['data']['settlement_ids']);
        $this->assertSame('Increase Payments', $result['data']['action']);
        $this->assertSame('100.00', $result['data']['increase_amount']);
        $this->assertSame('368.73', $result['data']['total_amount']);
        $this->assertSame('2024-11-25', $result['data']['start_date']);
        $this->assertSame('2025-04-25', $result['data']['end_date']);
        $this->assertSame('Admin(Avinash)', $result['data']['requested_by']);
    }

    public function testParsePayloadSupportsAlternateKeys(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parsePayload([
            'customerId' => '392014299',
            'settlementId' => '5538063 - increase_option1 - 0',
            'Action' => 'Increase Payments',
            'increaseAmount' => '$1,100',
            'totalAmount' => '1,368.73',
            'startDate' => '11/25/2024',
            'endDate' => '2025-04-25',
            'requestedBy' => 'Admin(Avinash)',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('392014299', $result['data']['customer_id']);
        $this->assertSame('5538063', $result['data']['settlement_id']);
        $this->assertSame(['5538063'], $result['data']['settlement_ids']);
        $this->assertSame('1100.00', $result['data']['increase_amount']);
        $this->assertSame('1368.73', $result['data']['total_amount']);
        $this->assertSame('2024-11-25', $result['data']['start_date']);
        $this->assertSame('2025-04-25', $result['data']['end_date']);
    }

    public function testParsePayloadSupportsApiAliasesAndTier3StructuredData(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parsePayload([
            'contact_id' => '1153799588',
            'action' => 'add_creditor_and_extend_program',
            'portal_user' => 'client@example.com',
            'new_date' => '05/15/2026',
            'new_amount' => '$1,250.50',
            'settlement_ids' => ['12345', '67890'],
            'creditor_name' => 'Capital One',
            'account_number' => '9988',
            'months_to_extend' => '6',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('1153799588', $result['data']['customer_id']);
        $this->assertSame('client@example.com', $result['data']['requested_by']);
        $this->assertSame('2026-05-15', $result['data']['target_date']);
        $this->assertSame('1250.50', $result['data']['amount']);
        $this->assertSame(['12345', '67890'], $result['data']['settlement_ids']);
        $this->assertSame('Capital One', $result['data']['creditor_change']['creditor_name']);
        $this->assertSame('6', $result['data']['creditor_change']['months_to_extend']);
    }

    public function testParseRawTextSupportsChangePaymentShape(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Customer Id: LLG-392014299',
            'Action: Reschedule Payment',
            'Original Scheduled Date: 11/25/2024',
            'New Scheduled Date: 2024-12-03',
            'Payment Amount: $250.50',
            'Void Settlement: 5538063, 5539001',
            'User: Admin(Avinash)',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame('392014299', $result['data']['customer_id']);
        $this->assertSame('Reschedule Payment', $result['data']['action']);
        $this->assertSame('250.50', $result['data']['amount']);
        $this->assertSame(['250.50'], $result['data']['amounts']);
        $this->assertSame('2024-11-25', $result['data']['original_date']);
        $this->assertSame(['2024-11-25'], $result['data']['original_dates']);
        $this->assertSame('2024-12-03', $result['data']['target_date']);
        $this->assertSame(['2024-12-03'], $result['data']['target_dates']);
        $this->assertSame(['5538063', '5539001'], $result['data']['settlement_ids']);
        $this->assertTrue($result['data']['void_settlements']);
    }

    public function testParsePayloadSupportsExtendProgramFields(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parsePayload([
            'customer_id' => '392014299',
            'action' => 'Extend Program',
            'Extended Months' => '11/25/2025, 04/25/2026',
            'Extended Amount' => '$175.25',
            'User' => 'Admin(Avinash)',
            'Company' => 'plaw',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('PLAW', $result['data']['company']);
        $this->assertSame('2025-11-25', $result['data']['extended_start_date']);
        $this->assertSame('2026-04-25', $result['data']['extended_end_date']);
        $this->assertSame('175.25', $result['data']['extended_amount']);
    }

    public function testParseRawTextPreservesSingleAmountWithThousandsSeparator(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Customer Id: 392014299',
            'Action: Making An Additional Deposit',
            'Date: 2025-02-15',
            'Lump Sum Amount: $1,100',
            'User: Admin(Avinash)',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame('1100.00', $result['data']['amount']);
        $this->assertSame(['1100.00'], $result['data']['amounts']);
        $this->assertSame('2025-02-15', $result['data']['target_date']);
    }

    public function testParseRawTextReturnsParserErrorsForInvalidFields(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'PMOD Approval For Customer Id: 392014299',
            'Settlement ID: no digits here',
            'Action: Increase Payments',
            'Increase Payment Amount: dollars',
            'Total Payment Amount: 200.00',
            'Start Date: 2024/11/25',
            'End Date: 2025-04-25',
            'User: Admin(Avinash)',
        ]));

        $this->assertArrayHasKey('settlement_id', $result['errors']);
        $this->assertArrayHasKey('increase_amount', $result['errors']);
        $this->assertArrayHasKey('start_date', $result['errors']);
        $this->assertSame('200.00', $result['data']['total_amount']);
        $this->assertSame('2025-04-25', $result['data']['end_date']);
    }
}
