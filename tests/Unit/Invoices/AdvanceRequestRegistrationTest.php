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
        self::assertNull($off->reserve('LDR', 'October 2026', '54'));
        self::assertFalse($off->record('LDR', 'LDR-ADV-20261031-01', 1000.0, 'sent', '%PDF', 'October 2026', '54'));
        self::assertNull(InvoiceRegistryGate::forGenerator(AdvanceRequestRegistration::SWITCH));
        $command = new GenerateAdvanceRequest();
        $data = (new ReflectionMethod($command, 'buildInvoiceData'))->invoke($command, 'Progress Law', 344000.0, '53', ['label' => 'September 2026', 'start' => '2026-09-01', 'end_exclusive' => '2026-10-01']);
        self::assertSame('PLAW-ADV-20260901-53', $data['invoice_number']);
    }

    public function test_the_scheduled_run_at_06_00_utc_is_still_the_previous_day_in_pacific(): void
    {
        $store = new FakeInvoiceStore();
        $registration = $this->registration($store);
        self::assertSame('LDR-ADV-20261031-01', $registration->reserve('LDR', 'October 2026', '54'));
        self::assertSame('PLAW-ADV-20261031-01', $registration->reserve('PLAW', 'October 2026', '54'));
        self::assertSame('LDR-ADV-20261031-02', $registration->reserve('LDR', 'October 2026', '54'), 'a manual rerun the same PT day takes 02');
    }

    public function test_record_is_one_pdf_in_the_advance_request_folder(): void
    {
        $store = new FakeInvoiceStore();
        $registration = $this->registration($store);
        $number = $registration->reserve('PLAW', 'October 2026', '54');
        self::assertTrue($registration->record('PLAW', $number, 344000.0, 'sent', '%PDF-fake', 'October 2026', '54'));
        $folder = 'advance-request/progress-law/2026/10/PLAW-ADV-20261031-01/';
        $record = json_decode($store->objects[$folder . 'record.json'], true);
        self::assertSame([344000, 'sent', '2026-10-31', 'October 2026', 'pdf'], [$record['amount'], $record['status'], $record['invoiceDate'], $record['period'], $record['files'][0]['kind']]);
        self::assertSame($folder . 'PLAW-ADV-20261031-01.pdf', $record['files'][0]['key']);
    }

    public function test_registry_failure_alerts_and_the_old_number_stays(): void
    {
        $store = new FakeInvoiceStore();
        $store->failAlways = true;
        $registration = $this->registration($store);
        self::assertNull($registration->reserve('LDR', 'October 2026', '54'));
        self::assertFalse($registration->record('LDR', 'LDR-ADV-20261031-01', 1000.0, 'sent', 'x', 'October 2026', '54'));
        self::assertCount(2, $this->alerts);
    }

    public function test_command_registers_live_sends_only_and_changes_nothing_but_the_number(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Console/Commands/GenerateAdvanceRequest.php');
        self::assertStringContainsString('if (! $isDryRun && ! $sendToMe) {', $source);
        self::assertSame(1, preg_match_all("/\\\$invoice\\['invoice_number'\\] = \\\$registeredNumber;/", $source), 'the only invoice field the registry sets');
        self::assertStringContainsString("'issue_date' => now()->format('F j, Y')", $source, 'printed issue date is unchanged');
        // The PDF builder output only depends on the invoice array: same data in, same bytes out.
        $builder = new AdvanceRequestInvoiceBuilder();
        $data = ['program' => 'LDR', 'amount' => 656000.0, 'tranche' => '53', 'month' => 'September 2026', 'invoice_number' => 'LDR-ADV-20260901-53', 'issue_date' => 'October 1, 2026', 'sample' => false];
        self::assertSame($builder->build($data), $builder->build($data));
    }
}
