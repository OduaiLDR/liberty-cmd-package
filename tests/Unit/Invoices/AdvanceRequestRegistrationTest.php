<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Console\Commands\GenerateAdvanceRequest;
use Cmd\Reports\Invoices\InvoiceRegistry;
use Cmd\Reports\Invoices\InvoiceRegistryGate;
use Cmd\Reports\Services\AdvanceRequestInvoiceBuilder;
use Cmd\Reports\Services\AdvanceRequestRegistration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdvanceRequestRegistrationTest extends TestCase
{
    private array $alerts = [];

    private function registration(FakeInvoiceStore $store, string $utc = '2026-11-01 06:00'): AdvanceRequestRegistration
    {
        $clock = static fn () => new \DateTimeImmutable($utc . ' UTC');

        return new AdvanceRequestRegistration(new InvoiceRegistryGate(new InvoiceRegistry($store, $clock), function (string $m): void { $this->alerts[] = $m; }));
    }

    public function test_off_by_default_and_old_number_format_is_untouched(): void
    {
        $off = new AdvanceRequestRegistration(null);
        self::assertNull($off->reserve('LDR', 'October 2026', '54', 'November 1, 2026'));
        self::assertFalse($off->record('LDR', 'LDR-ADV-20261031-01', 1000.0, 'sent', '%PDF', 'October 2026', '54', 'November 1, 2026'));
        self::assertNull(InvoiceRegistryGate::forGenerator(AdvanceRequestRegistration::SWITCH));
        $command = new GenerateAdvanceRequest();
        $data = (new ReflectionMethod($command, 'buildInvoiceData'))->invoke($command, 'Progress Law', 344000.0, '53', ['label' => 'September 2026', 'start' => '2026-09-01', 'end_exclusive' => '2026-10-01']);
        self::assertSame('PLAW-ADV-20260901-53', $data['invoice_number']);
    }

    public function test_the_number_date_is_the_printed_invoice_date_whatever_the_clock_says(): void
    {
        foreach (['2026-11-01 06:00', '2026-11-01 05:59', '2026-11-01 23:00', '2026-11-02 06:30', '2026-10-31 12:00'] as $utc) {
            $store = new FakeInvoiceStore();
            $registration = $this->registration($store, $utc);
            self::assertSame('LDR-ADV-20261101-01', $registration->reserve('LDR', 'October 2026', '54', 'November 1, 2026'), $utc);
            self::assertSame('PLAW-ADV-20261101-01', $registration->reserve('PLAW', 'October 2026', '54', 'November 1, 2026'), $utc);
            self::assertSame('LDR-ADV-20261101-02', $registration->reserve('LDR', 'October 2026', '54', 'November 1, 2026'), 'a rerun printing the same date takes 02');
        }
        self::assertSame('2026-11-01', AdvanceRequestRegistration::printedDate('November 1, 2026'));
        self::assertSame('2026-12-09', AdvanceRequestRegistration::printedDate('December 9, 2026'));
        self::assertSame('nonsense', AdvanceRequestRegistration::printedDate('nonsense'));
        self::assertSame('February 30, 2026', AdvanceRequestRegistration::printedDate('February 30, 2026'), 'impossible dates are not rolled over');
    }

    public function test_unreadable_printed_date_alerts_and_keeps_the_old_number(): void
    {
        $registration = $this->registration(new FakeInvoiceStore());
        self::assertNull($registration->reserve('LDR', 'October 2026', '54', 'soon'));
        self::assertCount(1, $this->alerts);
    }

    public function test_record_is_one_pdf_in_the_advance_request_folder_dated_by_the_printed_date(): void
    {
        $store = new FakeInvoiceStore();
        $registration = $this->registration($store, '2026-11-01 06:00');
        $number = $registration->reserve('PLAW', 'October 2026', '54', 'November 1, 2026');
        self::assertTrue($registration->record('PLAW', $number, 344000.0, 'sent', '%PDF-fake', 'October 2026', '54', 'November 1, 2026'));
        $folder = 'advance-request/progress-law/2026/11/PLAW-ADV-20261101-01/';
        $record = json_decode($store->objects[$folder . 'record.json'], true);
        self::assertSame([344000, 'sent', '2026-11-01', 'October 2026', 'pdf'], [$record['amount'], $record['status'], $record['invoiceDate'], $record['period'], $record['files'][0]['kind']]);
        self::assertSame($folder . 'PLAW-ADV-20261101-01.pdf', $record['files'][0]['key']);
        self::assertArrayHasKey('_reservations/PLAW-ADV/20261101/01.json', $store->objects);
    }

    public function test_registry_failure_alerts_and_the_old_number_stays(): void
    {
        $store = new FakeInvoiceStore();
        $store->failAlways = true;
        $registration = $this->registration($store);
        self::assertNull($registration->reserve('LDR', 'October 2026', '54', 'November 1, 2026'));
        self::assertFalse($registration->record('LDR', 'LDR-ADV-20261101-01', 1000.0, 'sent', 'x', 'October 2026', '54', 'November 1, 2026'));
        self::assertCount(2, $this->alerts);
    }

    public function test_command_registers_live_sends_only_and_changes_nothing_but_the_number(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Console/Commands/GenerateAdvanceRequest.php');
        self::assertStringContainsString('if (! $isDryRun && ! $sendToMe) {', $source);
        self::assertSame(1, preg_match_all("/\\\$invoice\\['invoice_number'\\] = \\\$registeredNumber;/", $source), 'the only invoice field the registry sets');
        $defaultInvoice = (new ReflectionMethod(GenerateAdvanceRequest::class, 'buildInvoiceData'))->invoke(
            new GenerateAdvanceRequest(), 'LDR', 656000.0, '53', ['label' => 'September 2026', 'start' => '2026-09-01']
        );
        self::assertSame(now()->format('F j, Y'), $defaultInvoice['issue_date'], 'the default issue date remains today');
        self::assertSame(2, substr_count($source, "(string) \$invoice['issue_date']"), 'reserve and record both use the printed date, never run time');
        // The PDF builder output only depends on the invoice array: same data in, same bytes out.
        $builder = new AdvanceRequestInvoiceBuilder();
        $data = ['program' => 'LDR', 'amount' => 656000.0, 'tranche' => '53', 'month' => 'September 2026', 'invoice_number' => 'LDR-ADV-20260901-53', 'issue_date' => 'October 1, 2026', 'sample' => false];
        self::assertSame($builder->build($data), $builder->build($data));
    }
}
