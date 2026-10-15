<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\InvoiceRegistry;
use Cmd\Reports\Invoices\InvoiceRegistryGate;
use PHPUnit\Framework\TestCase;

final class GenerateLendingTowerInvoicesRegistryTest extends TestCase
{
    use LendingTowerHarness;

    private $out;
    private FakeInvoiceStore $store;
    private array $alerts = [];

    protected function setUp(): void
    {
        $this->bootHarness();
        $this->store = new FakeInvoiceStore();
    }

    protected function tearDown(): void
    {
        $this->shutHarness();
    }

    private function gate(): InvoiceRegistryGate
    {
        return new InvoiceRegistryGate(new InvoiceRegistry($this->store), function (string $m): void { $this->alerts[] = $m; });
    }

    public function test_switch_off_behaves_exactly_as_before(): void
    {
        $cmd = $this->command();
        $cmd->useInvoiceGate('PLAW', null);
        self::assertTrue($this->deliverLive($cmd));
        self::assertSame(0, $this->store->puts, 'nothing is written to the invoice bucket');
        self::assertSame([], $this->alerts);
        $files = $this->disk()->allFiles();
        sort($files);
        self::assertSame([
            'cmd/lending-tower-invoices/2026-09/LT-PL-2026-09 backup.xlsx',
            'cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.pdf',
            'cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json',
        ], $files);
        self::assertStringContainsString('Invoice LT-PL-2026-09 - Lead Generation', $cmd->mail->sent[0]['subject'] ?? $cmd->mail->sent[0][3]);
        self::assertArrayNotHasKey('invoice_number', json_decode($this->disk()->get('cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json'), true));
    }

    public function test_switch_on_reserves_a_pacific_dated_number_and_records_the_invoice(): void
    {
        $cmd = $this->command();
        $cmd->useInvoiceGate('PLAW', $this->gate());
        self::assertTrue($this->deliverLive($cmd));

        $folder = 'lending-tower/progress-law/2026/10/LT-PL-20261001-01/';
        self::assertArrayHasKey('_reservations/LT-PL/20261001/01.json', $this->store->objects);
        self::assertSame([$folder . 'LT-PL-20261001-01.pdf', $folder . 'LT-PL-20261001-01-backup.xlsx', $folder . 'record.json'], array_values(array_filter(array_keys($this->store->objects), fn ($k) => ! str_starts_with($k, '_'))));
        $record = json_decode($this->store->objects[$folder . 'record.json'], true);
        self::assertSame(['LT-PL-20261001-01', 'lending-tower', 'progress-law', 550, 'sent', '2026-10-01', 'September 2026'], [
            $record['invoiceNumber'], $record['company'], $record['type'], $record['amount'], $record['status'], $record['invoiceDate'], $record['period'],
        ]);
        self::assertStringStartsWith('%PDF', $this->store->objects[$folder . 'LT-PL-20261001-01.pdf']);
        $args = $cmd->mail->sent[0];
        self::assertStringContainsString('LT-PL-20261001-01', $args['subject'] ?? $args[3]);
        // Old-style marker keeps its period name (billingStart() and reruns depend on it) and notes the new number.
        $marker = json_decode($this->disk()->get('cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json'), true);
        self::assertSame('LT-PL-20261001-01', $marker['invoice_number']);
        self::assertTrue($this->disk()->exists('cmd/lending-tower-invoices/2026-09/LT-PL-20261001-01.pdf'));
    }

    public function test_rerun_after_a_send_reserves_nothing_and_skips(): void
    {
        $cmd = $this->command();
        $cmd->useInvoiceGate('PLAW', $this->gate());
        $this->deliverLive($cmd);
        $objects = $this->store->objects;
        $again = $this->command();
        $again->useInvoiceGate('PLAW', $this->gate());
        self::assertTrue($this->deliverLive($again));
        self::assertSame($objects, $this->store->objects, 'no second reservation or record');
        self::assertSame([], $again->mail->sent, 'no second email');
    }

    public function test_registry_failure_logs_loudly_and_the_invoice_still_goes_out_with_the_old_number(): void
    {
        $this->store->failAlways = true;
        $cmd = $this->command();
        $cmd->useInvoiceGate('PLAW', $this->gate());
        self::assertTrue($this->deliverLive($cmd));
        self::assertNotEmpty($this->alerts);
        self::assertStringContainsString('using the OLD number', $this->alerts[0]);
        self::assertTrue($this->disk()->exists('cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json'));
        self::assertCount(1, $cmd->mail->sent);
    }

    public function test_record_failure_after_the_send_alerts_but_does_not_fail_the_run(): void
    {
        $cmd = $this->command();
        $gate = $this->gate();
        $cmd->useInvoiceGate('PLAW', $gate);
        // Reservation succeeds, then S3 goes away before the record is written.
        $this->store->objects['lending-tower/progress-law/2026/10/LT-PL-20261001-01/record.json'] = '{}';
        self::assertTrue($this->deliverLive($cmd));
        self::assertStringContainsString('NOT recorded', implode(' ', $this->alerts));
        self::assertTrue($this->disk()->exists('cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json'));
    }

    public function test_failed_email_is_recorded_as_issued_and_no_marker_is_written(): void
    {
        $cmd = $this->command();
        $cmd->mail->ok = false;
        $cmd->useInvoiceGate('PLAW', $this->gate());
        self::assertFalse($this->deliverLive($cmd));
        $record = json_decode($this->store->objects['lending-tower/progress-law/2026/10/LT-PL-20261001-01/record.json'], true);
        self::assertSame('issued', $record['status']);
        self::assertFalse($this->disk()->exists('cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json'));
    }

    public function test_test_sends_and_saves_never_reserve_a_number(): void
    {
        $cmd = $this->command();
        $cmd->useInvoiceGate('PLAW', $this->gate());
        $this->deliverLive($cmd, ['PLAW'], false, 'review@example.com');
        self::assertSame(0, $this->store->puts);
        self::assertTrue($cmd->mail->sent[0]['test'] ?? false, 'went out as a TEST email');
        self::assertStringContainsString('LT-PL-2026-09', $cmd->mail->sent[0][0]);
    }

    public function test_the_real_switch_defaults_to_off(): void
    {
        self::assertNull(InvoiceRegistryGate::forGenerator('INVOICE_LT_PL_ENABLED'));
    }
}
