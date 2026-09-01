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

    /**
     * Verbatim body of a real Progress Law portal email (contact 1228154188,
     * 2026-08). Two things this pins down, both of which used to be broken:
     * the SECOND `Action:` line must not win, and the bank labels must land in
     * banking_update.
     */
    public function testParseRawTextSupportsUpdatedBankingPortalEmail(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Name: Antonette U Tulenkun',
            'Customer Id: 1228154188',
            'Action: Updated Banking',
            'New Bank: ALOHA PACIFIC FEDERAL CREDIT UNION',
            'Account type: Savings',
            'New Routing Number: 321379148',
            'New Account Number: 13000003555732',
            'Set as default account for future payments?: Yes',
            'Authorization: Uploaded',
            'Action: Request Release',
            'Message: Updated authorization uploaded for your review, please release hold. Thank you.',
            'User: Client',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame('1228154188', $result['data']['customer_id']);
        $this->assertSame('Updated Banking', $result['data']['action']);
        $this->assertSame('Client', $result['data']['requested_by']);

        $banking = $result['data']['banking_update'];
        $this->assertSame('ALOHA PACIFIC FEDERAL CREDIT UNION', $banking['bank_name']);
        $this->assertSame('Savings', $banking['account_type']);
        $this->assertSame('321379148', $banking['routing_number']);
        $this->assertSame('13000003555732', $banking['account_number']);
        $this->assertSame('Uploaded', $banking['authorization']);

        // The bank labels are shared with Updated Sponsor Banking, so a CLIENT
        // banking email must not come out carrying a sponsor block.
        $this->assertSame([], $result['data']['sponsor_update']);
    }

    /**
     * Verbatim body of a real Progress Law portal email (contact 1201385085,
     * 2026-08). `Creditor:` is a bare string, so the container-key path never
     * matched it and the whole creditor block came back empty. `$1993` must also
     * survive as a number - the handlers cast balance to float, and (float)"$1993"
     * is 0.0, which would create the debt for nothing.
     */
    public function testParseRawTextSupportsRemoveCreditorPortalEmail(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Name: Xochitl Willis',
            'Customer Id: 1201385085',
            'Action: Remove Creditor and Decrease Payment',
            'Remove Reason: Entered payment arrangement on my own',
            'Creditor: UPSTART/DRBANK',
            'Account number: SDDR6300043',
            'Original Balance: $1993',
            'Number of Payments Reduced: 6',
            'New payment: $331.95',
            'Next Draft date: 08/31/2026',
            'User: Client',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame('1201385085', $result['data']['customer_id']);
        $this->assertSame('Remove Creditor and Decrease Payment', $result['data']['action']);
        $this->assertSame('331.95', $result['data']['amount']);

        $creditor = $result['data']['creditor_change'];
        $this->assertSame('UPSTART/DRBANK', $creditor['creditor_name']);
        $this->assertSame('SDDR6300043', $creditor['account_number']);
        $this->assertSame('1993.00', $creditor['balance']);
        $this->assertSame('6', $creditor['months_to_decrease']);
    }

    /**
     * Verbatim body of a real Progress Law portal email (contact 983537452,
     * 2026-08). The portal sent an EMPTY amount, so the request is genuinely
     * incomplete: the date must still parse, and the missing amount must surface
     * as a parser error rather than silently becoming zero.
     */
    public function testParseRawTextSupportsAdditionalPaymentPortalEmailWithEmptyAmount(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Name: Denys Tsuguy',
            'Customer Id: 983537452',
            'Action: Make an additional payment',
            'Bank Account: ....5815',
            'Scheduled Date: 08/27/2026',
            'Additional Amount: $',
            'User: Client',
        ]));

        $this->assertSame('983537452', $result['data']['customer_id']);
        $this->assertSame('Make an additional payment', $result['data']['action']);
        $this->assertSame('2026-08-27', $result['data']['target_date']);
        $this->assertNull($result['data']['amount']);
        $this->assertArrayHasKey('amounts', $result['errors']);
    }

    /**
     * Updated Sponsor Banking reuses the client bank labels; only the sponsor
     * identity fields separate the two shapes.
     */
    public function testParseRawTextRoutesSponsorBankingToSponsorUpdate(): void
    {
        $parser = new PmodApprovalParser();

        $result = $parser->parseRawText(implode("\n", [
            'Customer Id: 1201385085',
            'Action: Updated Sponsor Banking',
            'Sponsor Name: Jane Q Sponsor',
            'Sponsor SSN: 123-45-6789',
            'New Bank: WELLS FARGO',
            'Account type: Checking',
            'New Routing Number: 121000248',
            'New Account Number: 9988776655',
            'User: Client',
        ]));

        $sponsor = $result['data']['sponsor_update'];
        $this->assertSame('Jane Q Sponsor', $sponsor['sponsor_name']);
        $this->assertSame('123-45-6789', $sponsor['sponsor_ssn']);
        $this->assertSame('WELLS FARGO', $sponsor['sponsor_bank_name']);
        $this->assertSame('Checking', $sponsor['sponsor_account_type']);
        $this->assertSame('121000248', $sponsor['sponsor_routing_number']);
        $this->assertSame('9988776655', $sponsor['sponsor_account_number']);
    }
}
