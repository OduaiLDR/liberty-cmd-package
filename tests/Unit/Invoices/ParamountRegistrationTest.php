<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Console\Commands\GenerateParamountEpfSummary\ParamountInvoice;
use Cmd\Reports\Console\Commands\GenerateParamountEpfSummary\ParamountRegistration;
use Cmd\Reports\Invoices\InvoiceRegistry;
use Cmd\Reports\Invoices\InvoiceRegistryGate;
use PHPUnit\Framework\TestCase;

final class ParamountRegistrationTest extends TestCase
{
    private array $alerts = [];

    private function window(): array
    {
        return ['label' => 'September 2026', 'start' => '2026-09-01', 'endExclusive' => '2026-10-01'];
    }

    private function registration(FakeInvoiceStore $store): ParamountRegistration
    {
        return new ParamountRegistration(new InvoiceRegistryGate(new InvoiceRegistry($store), function (string $m): void { $this->alerts[] = $m; }));
    }

    public function test_switch_off_reserves_nothing_and_the_document_is_unchanged(): void
    {
        $off = new ParamountRegistration(null);
        self::assertNull($off->reserve($this->window()));
        self::assertFalse($off->record('LDR-PAR-20261001-01', $this->window(), 100.0, 'sent', '%PDF', 'xlsx'));
        self::assertNull(InvoiceRegistryGate::forGenerator(ParamountRegistration::SWITCH), 'default env is off');
        $doc = ParamountInvoice::document($this->window(), 1000.0, 2, 250.0, ['name' => 'Paramount Law', 'lines' => ['x']]);
        self::assertSame('LDR-PAR-2026-09', $doc['number']);
        self::assertStringContainsString('LDR-PAR-2026-09', $doc['notes'][1]);
    }

    public function test_switch_on_reserves_dated_like_the_invoice_and_records_it(): void
    {
        $store = new FakeInvoiceStore();
        $registration = $this->registration($store);
        $number = $registration->reserve($this->window());
        self::assertSame('LDR-PAR-20261001-01', $number);

        $doc = ParamountInvoice::document($this->window(), 1000.0, 2, 250.0, ['name' => 'Paramount Law', 'lines' => ['x']], $number);
        $old = ParamountInvoice::document($this->window(), 1000.0, 2, 250.0, ['name' => 'Paramount Law', 'lines' => ['x']]);
        self::assertSame($number, $doc['number']);
        self::assertStringContainsString($number, $doc['notes'][1]);
        $same = static fn (array $d) => array_diff_key($d, ['number' => 1, 'notes' => 1]);
        self::assertSame($same($old), $same($doc), 'only the number (and its mention in the note) differs');

        self::assertTrue($registration->record($number, $this->window(), 250.0, 'sent', '%PDF-fake', 'PKfake'));
        $folder = 'paramount/paramount/2026/10/LDR-PAR-20261001-01/';
        $record = json_decode($store->objects[$folder . 'record.json'], true);
        self::assertSame([250, 'sent', '2026-10-01', 'September 2026'], [$record['amount'], $record['status'], $record['invoiceDate'], $record['period']]);
        self::assertSame([$folder . 'LDR-PAR-20261001-01.pdf', $folder . 'LDR-PAR-20261001-01-epf-summary.xlsx'], array_column($record['files'], 'key'));
    }

    public function test_registry_failure_is_loud_and_returns_null_so_the_old_number_is_used(): void
    {
        $store = new FakeInvoiceStore();
        $store->failAlways = true;
        $registration = $this->registration($store);
        self::assertNull($registration->reserve($this->window()));
        self::assertFalse($registration->record('LDR-PAR-20261001-01', $this->window(), 250.0, 'sent', 'a', 'b'));
        self::assertCount(2, $this->alerts);
    }

    public function test_command_registers_only_for_live_invoice_sends_and_keeps_the_period_marker(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Console/Commands/GenerateParamountEpfSummary/GenerateParamountEpfSummary.php');
        self::assertMatchesRegularExpression("/if \(\\\$this->option\('send'\)\) \{\s+\\\$registration = /", $source);
        self::assertStringContainsString("paramount-invoices/' . substr(\$window['start'], 0, 7) . '.sent.json'", $source, 'marker is still named by month');
    }
}
