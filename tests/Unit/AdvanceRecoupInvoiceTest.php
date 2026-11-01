<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport\AdvanceRecoupInvoice;
use Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport\GenerateAdvanceRecoupReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdvanceRecoupInvoiceTest extends TestCase
{
    private const WINDOW = [
        'start' => '2026-09-01',
        'endExclusive' => '2026-10-01',
        'label' => 'September 2026',
    ];

    public function test_negative_balance_generates_operation_to_primary_internal_transfer(): void
    {
        $invoice = AdvanceRecoupInvoice::document(self::WINDOW, -1234.56, '2026-09-30');

        self::assertSame('PL-PL-20260930', $invoice['number']);
        self::assertSame('invoices@progresslaw.com', $invoice['from']['email']);
        self::assertSame('Internal transfer amount', $invoice['total_label']);
        self::assertSame('#15313c', $invoice['brand']['accent']);
        self::assertSame('#15313c', $invoice['brand']['dark']);
        self::assertSame('#e7eff2', $invoice['brand']['light']);
        self::assertSame('$1,234.56', $invoice['total_due']);
        self::assertStringContainsString(
            'From Progress Law Operation Account to Progress Law Primary Account',
            $invoice['lines'][0]['detail']
        );
        self::assertStringContainsString('internal transfer', strtolower(implode(' ', $invoice['notes'])));
    }

    public function test_positive_balance_reverses_transfer_direction(): void
    {
        $invoice = AdvanceRecoupInvoice::document(self::WINDOW, 25.0, '2026-09-30');

        self::assertSame('$25.00', $invoice['total_due']);
        self::assertStringContainsString(
            'From Progress Law Primary Account to Progress Law Operation Account',
            $invoice['lines'][0]['detail']
        );
    }

    public function test_zero_balance_produces_zero_transfer(): void
    {
        $invoice = AdvanceRecoupInvoice::document(self::WINDOW, 0.0, '2026-09-30');

        self::assertSame('$0.00', $invoice['total_due']);
        self::assertStringContainsString(
            'No account transfer - the balance due is zero',
            $invoice['lines'][0]['detail']
        );
    }

    public function test_non_finite_balance_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdvanceRecoupInvoice::document(self::WINDOW, INF, '2026-09-30');
    }

    public function test_report_supports_local_only_generation_without_email(): void
    {
        $command = new GenerateAdvanceRecoupReport();

        self::assertTrue($command->getDefinition()->hasOption('local-only'));
        self::assertTrue($command->getDefinition()->hasOption('test'));
    }
}
