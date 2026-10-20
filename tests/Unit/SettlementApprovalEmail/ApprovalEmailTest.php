<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\SettlementApprovalEmail\ApprovalEmail;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApprovalEmailTest extends TestCase
{
    public static function body(): string
    {
        return "Settlement Approval For Customer Id: LLG-123\r\nCreditor Name: Example: Bank\r\nSettlement ID: 456\r\nLast 4 Account Number: 0012";
    }

    public function test_brand_routing_and_final_line_without_a_trailing_newline(): void
    {
        foreach (['no-reply@libertydebtrelief.com' => 'LDR', 'no-reply@paramountlaw.com' => 'LDR', 'no-reply@progresslaw.com' => 'PLAW'] as $sender => $brand) {
            $email = ApprovalEmail::parse(self::body(), $sender, 'Settlement Approval Data: 456');
            self::assertSame($brand, $email->company);
            self::assertSame('123', $email->contactId);
            self::assertSame('Example: Bank', $email->creditor);
            self::assertSame('0012', $email->lastFour);
        }
    }

    public function test_unrelated_or_wrong_sender_messages_are_not_owned_by_this_command(): void
    {
        self::assertNull(ApprovalEmail::company('client@example.com', 'Settlement Approval Data: 456'));
        self::assertNull(ApprovalEmail::company('no-reply@progresslaw.com', 'Client Approved Settlement'));
        self::assertNull(ApprovalEmail::company('no-reply@progresslaw.com', 'Re: Settlement Approval Data: 456'));
    }

    public function test_incomplete_conflicting_and_injected_fields_are_rejected(): void
    {
        foreach ([
            str_replace('Settlement ID: 456', 'Settlement ID: 1 OR 1=1', self::body()),
            str_replace('Last 4 Account Number: 0012', '', self::body()),
            self::body() . "\nSettlement ID: 789",
            str_replace('Settlement ID: 456', 'Settlement ID', self::body()),
        ] as $body) {
            try { ApprovalEmail::parse($body, 'no-reply@progresslaw.com', 'Settlement Approval Data:'); self::fail('Expected invalid input.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
