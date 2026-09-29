<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\AdvanceRequestInvoiceBuilder;
use PHPUnit\Framework\TestCase;

class AdvanceRequestInvoiceBuilderTest extends TestCase
{
    public function test_it_builds_the_approved_ldr_invoice_without_sample_wording(): void
    {
        $builder = new AdvanceRequestInvoiceBuilder(
            dirname(__DIR__, 2) . '/resources/images/advance-request'
        );

        $pdf = $builder->build([
            'program' => 'LDR',
            'amount' => 656000,
            'tranche' => '53',
            'month' => 'September 2026',
            'invoice_number' => 'LDR-ADV-20260901-53',
            'issue_date' => 'September 28, 2026',
            'sample' => false,
        ]);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Count 1', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('WIRE INSTRUCTIONS', $pdf);
        self::assertStringNotContainsString('SAMPLE', $pdf);
        self::assertStringContainsString('322271627', $pdf);
        self::assertStringContainsString('219935100', $pdf);
    }

    public function test_it_builds_the_approved_progress_law_invoice(): void
    {
        $builder = new AdvanceRequestInvoiceBuilder(
            dirname(__DIR__, 2) . '/resources/images/advance-request'
        );

        $pdf = $builder->build([
            'program' => 'Progress Law',
            'amount' => 344000,
            'tranche' => '53',
            'month' => 'September 2026',
            'invoice_number' => 'PLAW-ADV-20260901-53',
            'issue_date' => 'September 28, 2026',
            'sample' => false,
        ]);

        self::assertStringContainsString('Progress Law', $pdf);
        self::assertStringContainsString('044000037', $pdf);
        self::assertStringContainsString('2908959852', $pdf);
        self::assertStringNotContainsString('SAMPLE', $pdf);
    }
}
