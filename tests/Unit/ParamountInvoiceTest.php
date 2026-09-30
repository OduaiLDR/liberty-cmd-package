<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateParamountEpfSummary\ParamountInvoice;
use Cmd\Reports\Console\Commands\GenerateParamountEpfSummary\GenerateParamountEpfSummary;
use Illuminate\Console\OutputStyle;
use Mockery;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ParamountInvoiceTest extends TestCase
{
    public function test_invoice_uses_existing_tier_payment_not_debt_as_amount(): void
    {
        $doc = ParamountInvoice::document(['start' => '2026-08-01', 'endExclusive' => '2026-09-01', 'label' => 'August 2026'],
            45000, 4, 8925, ['name' => 'Paramount Law', 'lines' => []]);
        self::assertSame('$8,925.00', $doc['total_due']);
        self::assertSame('$45,000.00', $doc['lines'][0]['quantity']);
        self::assertSame('T4', $doc['lines'][0]['rate']);
        self::assertSame('LDR-PAR-2026-08', $doc['number']);
        self::assertSame('September 1, 2026', $doc['issue_date']);
        self::assertSame('Upon Receipt', $doc['due_date']);
        self::assertSame('Upon Receipt', $doc['terms']);
        self::assertSame('invoices@libertydebtrelief.com', $doc['from']['email']);
        self::assertStringNotContainsString('DRAFT', implode(' ', $doc['notes']));
        self::assertStringStartsWith('data:image/jpeg;base64,', $doc['logo_data_uri']);
    }

    public function test_confirmed_bill_to_does_not_carry_draft_notice(): void
    {
        $doc = ParamountInvoice::document(['start' => '2026-08-01', 'endExclusive' => '2026-09-01', 'label' => 'August 2026'],
            45000, 4, 8925, ['name' => 'Paramount Law', 'lines' => ['Confirmed billing address']]);
        self::assertStringNotContainsString('DRAFT', implode(' ', $doc['notes']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_private_invoice_email_has_only_requested_recipient_and_ldr_invoice_sender(): void
    {
        $xlsx = tempnam(sys_get_temp_dir(), 'paramount-test-');
        $pdf = tempnam(sys_get_temp_dir(), 'paramount-pdf-');
        file_put_contents($xlsx, 'workbook');
        file_put_contents($pdf, '%PDF-test');
        try {
            $mailer = Mockery::mock('overload:Cmd\\Reports\\Services\\EmailSenderService');
            $mailer->shouldReceive('__construct')->once()->with('GRAPH_LT');
            $mailer->shouldReceive('sendMailHtml')->once()->withArgs(function ($subject, $body, $to, $cc, $bcc, $attachments, $from) {
                self::assertSame(['oduai@libertydebtrelief.com'], $to);
                self::assertSame([], $cc);
                self::assertSame([], $bcc);
                self::assertSame('invoices@libertydebtrelief.com', $from);
                self::assertCount(2, $attachments);
                self::assertSame('application/pdf', $attachments[1]['contentType']);
                self::assertStringContainsString('[TEST]', $subject);
                self::assertStringContainsString('Here is the EPF Summary for August 2026.', $body);
                self::assertStringContainsString('<strong>$45,000.00</strong>', $body);
                self::assertStringContainsString('<strong>T4</strong>', $body);
                self::assertStringContainsString('<strong>$8,925.00</strong>', $body);
                self::assertStringNotContainsString('Attached are', $body);
                return true;
            })->andReturn(true);
            $command = new GenerateParamountEpfSummary();
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
            (new ReflectionMethod($command, 'sendReport'))->invoke($command,
                ['filename' => 'backup.xlsx', 'path' => $xlsx], 'August 2026', 45000.0, 4, 8925.0, false, 'oduai@libertydebtrelief.com', $pdf);
        } finally {
            Mockery::close();
            unlink($xlsx);
            unlink($pdf);
        }
    }
}
