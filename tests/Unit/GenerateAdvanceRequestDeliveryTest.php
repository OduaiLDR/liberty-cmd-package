<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateAdvanceRequest;
use Cmd\Reports\Services\ReviewInvoiceNumbers;
use Cmd\Reports\Tests\Unit\Invoices\TestFiles;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GenerateAdvanceRequestDeliveryTest extends TestCase
{
    public function test_command_exposes_only_the_safe_verification_override(): void
    {
        $command = new GenerateAdvanceRequest();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('month'));
        self::assertTrue($definition->hasOption('advance-month'));
        self::assertTrue($definition->hasOption('invoice-date'));
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertTrue($definition->hasOption('send-to-me'));
        self::assertFalse($definition->hasOption('review'));
        self::assertFalse($definition->hasOption('sample'));
        self::assertTrue($definition->hasOption('company'));
        self::assertSame('all', $definition->getOption('company')->getDefault());
        self::assertFalse($definition->hasOption('send-live'));

        $reflection = new ReflectionClass($command);
        self::assertSame(
            'oduai@libertydebtrelief.com',
            $reflection->getReflectionConstant('VERIFICATION_RECIPIENT')->getValue()
        );
    }

    public function test_email_body_is_clean_and_references_the_invoice(): void
    {
        $command = new GenerateAdvanceRequest();
        $method = (new ReflectionClass($command))->getMethod('buildEmailBody');
        $body = $method->invoke($command, 'LDR', 656000.0, '53', 'September 2026');

        self::assertStringContainsString('$656,000.00', $body);
        self::assertStringContainsString('attached invoice', $body);
        self::assertStringNotContainsString('REVIEW', strtoupper($body));
        self::assertStringNotContainsString('SAMPLE', strtoupper($body));
    }

    public function test_verification_uses_each_company_production_sender_and_credentials(): void
    {
        $command = new GenerateAdvanceRequest();
        $method = (new ReflectionClass($command))->getMethod('resolveVerificationSender');
        $prefix = (new ReflectionClass($command))->getMethod('credentialPrefix');
        self::assertSame('PLAW_MS', $prefix->invoke($command, 'PLAW'));
        self::assertSame('MS', $prefix->invoke($command, 'LDR'));

        self::assertSame('NGF@progresslaw.com', $method->invoke($command, [
            'company' => 'PLAW',
            'sender' => 'NGF@progresslaw.com',
        ]));
        self::assertSame('NGF@libertydebtrelief.com', $method->invoke($command, [
            'company' => 'LDR',
            'sender' => 'NGF@libertydebtrelief.com',
        ]));
    }

    public function test_december_advance_can_use_october_data_with_a_november_invoice_date(): void
    {
        $command = new GenerateAdvanceRequest();
        $command->setInput(new ArrayInput([
            '--month' => '2026-10', '--advance-month' => '2026-12', '--invoice-date' => '2026-11-01', '--send-to-me' => true,
        ], $command->getDefinition()));
        $reflection = new ReflectionClass($command);
        $queryWindow = $reflection->getMethod('resolveMonthWindow')->invoke($command);
        $displayWindow = $reflection->getMethod('resolveMonthWindow')->invoke($command, 'advance-month', $queryWindow);
        $issueDate = $reflection->getMethod('resolveInvoiceDate')->invoke($command);
        $invoice = $reflection->getMethod('buildInvoiceData')->invoke($command, 'LDR', 600000.0, '53', $displayWindow, $issueDate);

        self::assertSame(['label' => 'October 2026', 'start' => '2026-10-01', 'end_exclusive' => '2026-11-01'], $queryWindow);
        self::assertSame('December 2026', $invoice['month']);
        self::assertSame('November 1, 2026', $invoice['issue_date']);
        self::assertSame('LDR-ADV-20261201-53', $invoice['invoice_number']);
        self::assertFalse($invoice['sample']);
    }

    public function test_advance_month_defaults_to_the_query_month(): void
    {
        $command = new GenerateAdvanceRequest();
        $command->setInput(new ArrayInput(['--month' => '2026-10'], $command->getDefinition()));
        $method = (new ReflectionClass($command))->getMethod('resolveMonthWindow');
        $window = $method->invoke($command);
        self::assertSame($window, $method->invoke($command, 'advance-month', $window));
    }

    public function test_invalid_invoice_date_is_rejected_before_delivery(): void
    {
        $command = new GenerateAdvanceRequest();
        $command->setInput(new ArrayInput(['--invoice-date' => '2026-02-30'], $command->getDefinition()));
        $this->expectException(\InvalidArgumentException::class);
        (new ReflectionClass($command))->getMethod('resolveInvoiceDate')->invoke($command);
    }

    public function test_advance_review_numbers_use_the_confirmed_company_pairs_only(): void
    {
        $root = sys_get_temp_dir() . '/advance-review-' . bin2hex(random_bytes(6));
        try {
            $command = new GenerateAdvanceRequest();
            $command->useReviewInvoiceNumbers(new ReviewInvoiceNumbers($root));
            $method = (new ReflectionClass($command))->getMethod('applyReviewNumber');
            $date = new \DateTimeImmutable('2026-11-01');
            $data = ['invoice_number' => 'old', 'amount' => 633000.0, 'month' => 'December 2026'];
            $first = $method->invoke($command, $data, 'LDR', $date, true);
            self::assertSame('LDR-NGF-20261101-01', $first['invoice_number']);
            self::assertSame(633000.0, $first['amount']);
            self::assertSame('December 2026', $first['month']);
            self::assertSame('LDR-NGF-20261101-02', $method->invoke($command, $data, 'LDR', $date, true)['invoice_number']);
            self::assertSame('PL-NGF-20261101-01', $method->invoke($command, $data, 'PLAW', $date, true)['invoice_number']);
        } finally {
            TestFiles::removeTree($root);
        }
    }

    public function test_review_terms_cannot_be_sent_to_normal_live_recipients(): void
    {
        $command = new GenerateAdvanceRequest();
        $input = new ArrayInput(['--month' => '2026-10', '--review-terms' => true], $command->getDefinition());
        $output = new BufferedOutput();
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        self::assertSame(1, $command->handle());
        self::assertStringContainsString('--review-terms requires', $output->fetch());
    }
}
